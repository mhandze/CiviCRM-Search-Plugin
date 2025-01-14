<?php
/**
 * @version		
 * @package		Civicrm
 * @subpackage	Joomla Plugin
 * @copyright	Copyright (C) 2005 - 2011 Open Source Matters, Inc. All rights reserved.
 * @license		GNU General Public License version 2 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die;

jimport('joomla.plugin.plugin');

require_once JPATH_SITE.'/components/com_content/router.php';

/**
 * CiviCRM Search plugin
 *
 * @package     Joomla.Plugin
 * @subpackage  Search.content
 * @since       1.6
 */
class plgCiviSearch extends JPlugin
{
    /**
     * @return array An array of search areas
     */
    function onContentSearchAreas()
    {
        static $areas = array( 'events' => 'CiviCRM Events' );
        return $areas;
    }

    /**
     * Search method
     * The sql must return the following fields that are used in a common display
     * routine: href, title, section, created, text, browsernav
     * @param string Target search string
     * @param string mathcing option, exact|any|all
     * @param string ordering option, newest|oldest|popular|alpha|category
     * @param mixed An array if the search it to be restricted to areas, null if search all
     */
    function onContentSearch($text, $phrase='', $ordering='', $areas=null)
    {
        $db     = JFactory::getDbo();
        $app    = JFactory::getApplication();
        $user   = JFactory::getUser();
        $groups = implode(',', $user->getAuthorisedViewLevels());
        $tag = JFactory::getLanguage()->getTag();

        require_once JPATH_SITE.'/components/com_content/helpers/route.php';
        require_once JPATH_SITE.'/administrator/components/com_search/helpers/search.php';

        $searchText = $text;
        if (is_array($areas)) {
            if (!array_intersect($areas, array_keys($this->onContentSearchAreas()))) {
                return array();
            }
        }

        $sContent       = $this->params->get('search_content',      1);
        $sArchived      = $this->params->get('search_archived',     1);
        $limit          = $this->params->def('search_limit',        50);

        $nullDate       = $db->getNullDate();
        $date = JFactory::getDate();
        $now = $date->toMySQL();

        $text = trim($text);
        if ($text == '') {
            return array();
        }

        $wheres = array();
        switch ($phrase) {
            case 'exact':
                $text       = $db->Quote('%'.$db->getEscaped($text, true).'%', false);
                $wheres2    = array();
                $wheres2[]  = 'a.title LIKE '.$text;
                $wheres2[]  = 'a.introtext LIKE '.$text;
                $wheres2[]  = 'a.fulltext LIKE '.$text;
                $wheres2[]  = 'a.metakey LIKE '.$text;
                $wheres2[]  = 'a.metadesc LIKE '.$text;
                $where      = '(' . implode(') OR (', $wheres2) . ')';
                break;

            case 'all':
            case 'any':
            default:
                $words = explode(' ', $text);
                $wheres = array();
                foreach ($words as $word) {
                    $word       = $db->Quote('%'.$db->getEscaped($word, true).'%', false);
                    $wheres2    = array();
                    $wheres2[]  = 'a.title LIKE '.$word;
                    $wheres2[]  = 'a.introtext LIKE '.$word;
                    $wheres2[]  = 'a.fulltext LIKE '.$word;
                    $wheres2[]  = 'a.metakey LIKE '.$word;
                    $wheres2[]  = 'a.metadesc LIKE '.$word;
                    $wheres[]   = implode(' OR ', $wheres2);
                }
                $where = '(' . implode(($phrase == 'all' ? ') AND (' : ') OR ('), $wheres) . ')';
                break;
        }

        $morder = '';
        switch ($ordering) {
            case 'oldest':
                $order = 'a.created ASC';
                break;

            case 'popular':
                $order = 'a.hits DESC';
                break;

            case 'alpha':
                $order = 'a.title ASC';
                break;

            case 'category':
                $order = 'c.title ASC, a.title ASC';
                $morder = 'a.title ASC';
                break;

            case 'newest':
            default:
                $order = 'a.created DESC';
                break;
        }

        $rows = array();
        $query  = $db->getQuery(true);

        // search articles
        if ($sContent && $limit > 0)
        {
            $query->clear();
            $query->select('a.title AS title, a.metadesc, a.metakey, a.created AS created, '
                        .'CONCAT(a.introtext, a.fulltext) AS text, c.title AS section, '
                        .'CASE WHEN CHAR_LENGTH(a.alias) THEN CONCAT_WS(":", a.id, a.alias) ELSE a.id END as slug, '
                        .'CASE WHEN CHAR_LENGTH(c.alias) THEN CONCAT_WS(":", c.id, c.alias) ELSE c.id END as catslug, '
                        .'"2" AS browsernav');
            $query->from('#__content AS a');
            $query->innerJoin('#__categories AS c ON c.id=a.catid');
            $query->where('('. $where .')' . 'AND a.state=1 AND c.published = 1 AND a.access IN ('.$groups.') '
                        .'AND c.access IN ('.$groups.') '
                        .'AND (a.publish_up = '.$db->Quote($nullDate).' OR a.publish_up <= '.$db->Quote($now).') '
                        .'AND (a.publish_down = '.$db->Quote($nullDate).' OR a.publish_down >= '.$db->Quote($now).')' );
            $query->group('a.id');
            $query->order($order);

            // Filter by language
            if ($app->isSite() && $app->getLanguageFilter()) {
                $query->where('a.language in (' . $db->Quote($tag) . ',' . $db->Quote('*') . ')');
                $query->where('c.language in (' . $db->Quote($tag) . ',' . $db->Quote('*') . ')');
            }

            $db->setQuery($query, 0, $limit);
            $list = $db->loadObjectList();
            $limit -= count($list);

            if (isset($list))
            {
                foreach($list as $key => $item)
                {
                    $list[$key]->href = ContentHelperRoute::getArticleRoute($item->slug, $item->catslug);
                }
            }
            $rows[] = $list;
        }

        // search archived content
        if ($sArchived && $limit > 0)
        {
            $searchArchived = JText::_('JARCHIVED');

            $query->clear();
            $query->select('a.title AS title, a.metadesc, a.metakey, a.created AS created, '
                        .'CONCAT(a.introtext, a.fulltext) AS text, '
                        .'CASE WHEN CHAR_LENGTH(a.alias) THEN CONCAT_WS(":", a.id, a.alias) ELSE a.id END as slug, '
                        .'CASE WHEN CHAR_LENGTH(c.alias) THEN CONCAT_WS(":", c.id, c.alias) ELSE c.id END as catslug, '
                        .'CONCAT_WS("/", c.title) AS section, "2" AS browsernav' );
            $query->from('#__content AS a');
            $query->innerJoin('#__categories AS c ON c.id=a.catid AND c.access IN ('. $groups .')');
            $query->where('('. $where .') AND a.state = 2 AND c.published = 1 AND a.access IN ('. $groups
                .') AND c.access IN ('. $groups .') '
                .'AND (a.publish_up = '.$db->Quote($nullDate).' OR a.publish_up <= '.$db->Quote($now).') '
                .'AND (a.publish_down = '.$db->Quote($nullDate).' OR a.publish_down >= '.$db->Quote($now).')' );
            $query->order($order);


            // Filter by language
            if ($app->isSite() && $app->getLanguageFilter()) {
                $query->where('a.language in (' . $db->Quote($tag) . ',' . $db->Quote('*') . ')');
                $query->where('c.language in (' . $db->Quote($tag) . ',' . $db->Quote('*') . ')');
            }

            $db->setQuery($query, 0, $limit);
            $list3 = $db->loadObjectList();

            // find an itemid for archived to use if there isn't another one
            $item   = $app->getMenu()->getItems('link', 'index.php?option=com_content&view=archive', true);
            $itemid = isset($item) ? '&Itemid='.$item->id : '';

            if (isset($list3))
            {
                foreach($list3 as $key => $item)
                {
                    $date = JFactory::getDate($item->created);

                    $created_month  = $date->format("n");
                    $created_year   = $date->format("Y");

                    $list3[$key]->href  = JRoute::_('index.php?option=com_content&view=archive&year='.$created_year.'&month='.$created_month.$itemid);
                }
            }

            $rows[] = $list3;
        }

        $results = array();
        if (count($rows))
        {
            foreach($rows as $row)
            {
                $new_row = array();
                foreach($row AS $key => $article) {
                    if (searchHelper::checkNoHTML($article, $searchText, array('text', 'title', 'metadesc', 'metakey'))) {
                        $new_row[] = $article;
                    }
                }
                $results = array_merge($results, (array) $new_row);
            }
        }

        return $results;
    }
}
