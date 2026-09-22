<?php
// This file is part of Moodle - http://moodle.org/
//
// This CLI tool is licensed the same as mod_hvp: GNU GPL v3 or later.
/**
 * target_resolver.php
 *
 * Built for: Moodle 4.5.12 (2024100712), Ubuntu 24.04 LTS, PHP 8.3.32, MySQL 8.0.46, mod_hvp 1.28.4+.
 * 
 * NO GUARANTEES - `Experimental`
 * 
 * ALWAYS, ALWAYS, ALWAYS - Make a backup/snapshot of your entire database first to err on the side of caution.
 * 
 * Turns the --all / --category / --course / --id / --cmid / --library CLI
 * options into a concrete, de-duplicated, sorted list of hvp.id values to
 * operate on. Kept deliberately dumb and read-only: it never mutates data,
 * only queries it, which makes it trivial to unit-test and safe to call
 * repeatedly (e.g. once for a dry-run preview, once for the real run).
 *
 * @package    mod_hvp
 * @subpackage cli
 * @copyright  2026 Dave S. AS <corestaples@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_hvp\cli;

defined('MOODLE_INTERNAL') || defined('CLI_SCRIPT') || die('Direct access not permitted');

class target_resolver {

    /** @var \moodle_database */
    protected $db;

    public function __construct(\moodle_database $db) {
        $this->db = $db;
    }

    /**
     * Resolve CLI options into a list of hvp.id values.
     *
     * Recognised keys in $options (all optional except that at least one of
     * all/category/course/id/cmid must be truthy - enforced by the caller):
     *   - all       (bool)
     *   - category  (int[])  category ids, subcategories included
     *   - course    (int[])  course ids
     *   - id        (int[])  hvp.id values directly
     *   - cmid      (int[])  course_modules.id values
     *   - library   (string) machine name filter, e.g. "H5P.InteractiveBook"
     *   - libraryid (int[])  exact hvp_libraries.id filter - pins a specific
     *                        machine name AND version in one go (e.g. the row
     *                        for "H5P.InteractiveBook 1.15" specifically, not
     *                        just any installed version of that machine name).
     *                        Safer/more precise than --library when you know
     *                        exactly which library row you mean; find the id
     *                        via --list-libraries or a direct query against
     *                        hvp_libraries. Combines with --library as an
     *                        additional AND-narrowing filter if both are given,
     *                        though fix_subcontent.php's own validation keeps
     *                        them mutually exclusive at the CLI level.
     *   - excludeid (int[])  hvp.id values to explicitly exclude (used by --resume)
     *
     * @param array $options
     * @return int[] sorted, unique hvp.id values
     */
    public function resolve(array $options): array {
        $ids = [];

        if (!empty($options['id'])) {
            $ids = array_merge($ids, $this->by_content_id($options['id']));
        }

        if (!empty($options['cmid'])) {
            $ids = array_merge($ids, $this->by_cmid($options['cmid']));
        }

        if (!empty($options['course'])) {
            $ids = array_merge($ids, $this->by_course($options['course']));
        }

        if (!empty($options['category'])) {
            $ids = array_merge($ids, $this->by_category($options['category']));
        }

        if (!empty($options['all'])) {
            $ids = array_merge($ids, $this->all_ids());
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (!empty($options['library'])) {
            $ids = $this->filter_by_library($ids, $options['library']);
        }

        if (!empty($options['libraryid'])) {
            $ids = $this->filter_by_library_id($ids, $options['libraryid']);
        }

        if (!empty($options['excludeid'])) {
            $exclude = array_map('intval', $options['excludeid']);
            $ids = array_values(array_diff($ids, $exclude));
        }

        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @param int[] $ids
     * @return int[] hvp.id values matching the given ids (validates existence)
     */
    protected function by_content_id(array $ids): array {
        if (empty($ids)) {
            return [];
        }
        [$insql, $params] = $this->db->get_in_or_equal(array_map('intval', $ids), SQL_PARAMS_NAMED);
        return array_keys($this->db->get_records_select('hvp', "id {$insql}", $params, '', 'id'));
    }

    /**
     * @param int[] $cmids course_modules.id values for the mod_hvp module
     * @return int[] hvp.id values
     */
    protected function by_cmid(array $cmids): array {
        if (empty($cmids)) {
            return [];
        }
        global $DB;
        $moduleid = $this->db->get_field('modules', 'id', ['name' => 'hvp'], MUST_EXIST);
        [$insql, $params] = $this->db->get_in_or_equal(array_map('intval', $cmids), SQL_PARAMS_NAMED);
        $params['moduleid'] = $moduleid;
        $records = $this->db->get_records_select(
            'course_modules',
            "module = :moduleid AND id {$insql}",
            $params,
            '',
            'id, instance'
        );
        return array_values(array_map(function ($r) {
            return (int) $r->instance;
        }, $records));
    }

    /**
     * @param int[] $courseids
     * @return int[] hvp.id values
     */
    protected function by_course(array $courseids): array {
        if (empty($courseids)) {
            return [];
        }
        [$insql, $params] = $this->db->get_in_or_equal(array_map('intval', $courseids), SQL_PARAMS_NAMED);
        return array_keys($this->db->get_records_select('hvp', "course {$insql}", $params, '', 'id'));
    }

    /**
     * @param int[] $categoryids category ids; every course in these categories
     *                           AND all their subcategories is included.
     * @return int[] hvp.id values
     */
    protected function by_category(array $categoryids): array {
        if (empty($categoryids)) {
            return [];
        }

        // Expand to include subcategories using Moodle's materialised "path" column.
        $allcategoryids = [];
        foreach ($categoryids as $catid) {
            $catid = (int) $catid;
            $allcategoryids[$catid] = true;
            $path = $this->db->get_field('course_categories', 'path', ['id' => $catid]);
            if ($path === false) {
                continue;
            }
            // LIKE-based subcategory match against Moodle's materialised path column
            // (e.g. path '/1/2' matches any descendant path like '/1/2/3', '/1/2/3/4', ...).
            $subs = $this->db->get_records_select(
                'course_categories',
                $this->db->sql_like('path', ':pathlike'),
                ['pathlike' => $path . '/%']
            );
            foreach ($subs as $sub) {
                $allcategoryids[(int) $sub->id] = true;
            }
        }

        $allcategoryids = array_keys($allcategoryids);
        [$catinsql, $catparams] = $this->db->get_in_or_equal($allcategoryids, SQL_PARAMS_NAMED);
        $courseids = array_keys($this->db->get_records_select('course', "category {$catinsql}", $catparams, '', 'id'));

        if (empty($courseids)) {
            return [];
        }

        return $this->by_course($courseids);
    }

    /**
     * @return int[] every hvp.id on the site
     */
    protected function all_ids(): array {
        return array_keys($this->db->get_records('hvp', null, '', 'id'));
    }

    /**
     * Narrow a set of hvp.id values down to those whose ROOT (main_library_id)
     * library matches the given machine name. Note this only filters by the
     * root content type - it does not look inside subcontent - because the
     * purpose is "let me target all my Course Presentations", not "find
     * anything that happens to embed a Course Presentation somewhere".
     *
     * @param int[] $ids
     * @param string $machinename e.g. "H5P.InteractiveBook"
     * @return int[]
     */
    protected function filter_by_library(array $ids, string $machinename): array {
        if (empty($ids)) {
            return [];
        }
        [$insql, $params] = $this->db->get_in_or_equal(array_map('intval', $ids), SQL_PARAMS_NAMED, 'id');
        $params['machinename'] = $machinename;
        $sql = "SELECT h.id
                  FROM {hvp} h
                  JOIN {hvp_libraries} l ON l.id = h.main_library_id
                 WHERE h.id {$insql} AND l.machine_name = :machinename";
        return array_keys($this->db->get_records_sql($sql, $params));
    }

    /**
     * Narrow a set of hvp.id values down to those whose ROOT (main_library_id)
     * is exactly one of the given hvp_libraries.id values. Unlike
     * filter_by_library(), this pins an EXACT version, not just a machine
     * name - e.g. "H5P.InteractiveBook 1.15" specifically, not "whichever
     * version of H5P.InteractiveBook happens to be installed." Useful when
     * you've looked up the precise library row you mean (via --list-libraries
     * or a direct query against hvp_libraries) and want to target it without
     * any ambiguity if multiple versions of the same machine name are
     * installed side by side.
     *
     * @param int[] $ids
     * @param int[] $libraryids hvp_libraries.id values
     * @return int[]
     */
    protected function filter_by_library_id(array $ids, array $libraryids): array {
        if (empty($ids) || empty($libraryids)) {
            return [];
        }
        [$idinsql, $idparams] = $this->db->get_in_or_equal(array_map('intval', $ids), SQL_PARAMS_NAMED, 'id');
        [$libinsql, $libparams] = $this->db->get_in_or_equal(array_map('intval', $libraryids), SQL_PARAMS_NAMED, 'lib');
        $sql = "SELECT h.id
                  FROM {hvp} h
                 WHERE h.id {$idinsql} AND h.main_library_id {$libinsql}";
        return array_keys($this->db->get_records_sql($sql, array_merge($idparams, $libparams)));
    }
}
