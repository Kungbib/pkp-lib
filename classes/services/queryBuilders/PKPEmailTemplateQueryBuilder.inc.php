<?php
/**
 * @file classes/services/QueryBuilders/PKPEmailTemplateQueryBuilder.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PKPEmailTemplateQueryBuilder
 * @ingroup query_builders
 *
 * @brief Class for building database queries for email templates
 */

namespace PKP\services\queryBuilders;

use Illuminate\Support\Facades\DB;
use PKP\services\queryBuilders\interfaces\EntityQueryBuilderInterface;

class PKPEmailTemplateQueryBuilder implements EntityQueryBuilderInterface
{
    /** @var integer journal or press ID */
    protected $contextId = null;

    /** @var boolean enabled or disabled emails */
    protected $isEnabled = null;

    /** @var boolean custom emails with no default template */
    protected $isCustom = null;

    /** @var array filter by sender role IDs */
    protected $fromRoleIds = [];

    /** @var array filter by recipient role IDs */
    protected $toRoleIds = [];

    /** @var array filter by email keys */
    protected $keys = [];

    /** @var string search phrase */
    protected $searchPhrase = null;

    /** @var array filter by workflow stage IDs */
    protected $stageIds = [];

    /**
     * Set context filter
     *
     * @param $contextId integer
     *
     * @return \PKP\services\queryBuilders\PKPEmailTemplateQueryBuilder
     */
    public function filterByContext($contextId)
    {
        $this->contextId = $contextId;
        return $this;
    }

    /**
     * Set isEnabled filter
     *
     * @param $isEnabled boolean
     *
     * @return \PKP\services\queryBuilders\PKPEmailTemplateQueryBuilder
     */
    public function filterByIsEnabled($isEnabled)
    {
        $this->isEnabled = $isEnabled;
        return $this;
    }

    /**
     * Set isCustom filter
     *
     * @param $isCustom boolean
     *
     * @return \PKP\services\queryBuilders\PKPEmailTemplateQueryBuilder
     */
    public function filterByIsCustom($isCustom)
    {
        $this->isCustom = $isCustom;
        return $this;
    }

    /**
     * Set sender roles filter
     *
     * @param $fromRoleIds array
     *
     * @return \PKP\services\queryBuilders\PKPEmailTemplateQueryBuilder
     */
    public function filterByFromRoleIds($fromRoleIds)
    {
        $this->fromRoleIds = $fromRoleIds;
        return $this;
    }

    /**
     * Set recipient roles filter
     *
     * @param $toRoleIds array
     *
     * @return \PKP\services\queryBuilders\PKPEmailTemplateQueryBuilder
     */
    public function filterByToRoleIds($toRoleIds)
    {
        $this->toRoleIds = $toRoleIds;
        return $this;
    }

    /**
     * Set email keys filter
     *
     * @param $keys array
     *
     * @return \PKP\services\queryBuilders\PKPEmailTemplateQueryBuilder
     */
    public function filterByKeys($keys)
    {
        $this->keys = $keys;
        return $this;
    }

    /**
     * Set stage ID filter
     *
     * @param $stageIds array
     *
     * @return \PKP\services\queryBuilders\PKPEmailTemplateQueryBuilder
     */
    public function filterByStageIds($stageIds)
    {
        $this->stageIds = $stageIds;
        return $this;
    }

    /**
     * Set query search phrase
     *
     * @param $phrase string
     *
     * @return \PKP\services\queryBuilders\PKPEmailTemplateQueryBuilder
     */
    public function searchPhrase($phrase)
    {
        $this->searchPhrase = $phrase;
        return $this;
    }

    /**
     * Set query limit
     *
     * @param int $count
     *
     * @return \PKP\services\queryBuilders\PKPEmailTemplateQueryBuilder
     */
    public function limitTo($count)
    {
        $this->limit = $count;
        return $this;
    }

    /**
     * Set how many results to skip
     *
     * @param int $offset
     *
     * @return \PKP\services\queryBuilders\PKPEmailTemplateQueryBuilder
     */
    public function offsetBy($offset)
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Do not use this method.
     */
    public function getIds()
    {
        throw new \Exception('PKPEmailTemplateQueryBuilder::getIds() is not supported. Email templates should be referenced by key instead of id.');
    }

    /**
     * @copydoc PKP\services\queryBuilders\interfaces\EntityQueryBuilderInterface::getCount()
     */
    public function getCount()
    {
        $compiledQuery = $this->getCompiledQuery();
        return DB::table(DB::raw('(' . $compiledQuery[0] . ') as email_template_count'))
            ->setBindings($compiledQuery[1])
            ->count();
    }

    /**
     * Do not use this method.
     *
     * @see self::getCompiledQuery()
     */
    public function getQuery()
    {
        throw new \Exception('PKPEmailTemplateQueryBuilder::getQuery() is not supported. Use PKPEmailTemplateQueryBuilder::getCompiledQuery() instead.');
    }

    /**
     * Get the compiled SQL string and bindings
     *
     * This method performs a UNION on the default and custom template
     * tables, and returns the final SQL string and merged bindings.
     *
     * This is required due to a bug in Laravel's QueryBuilder when
     * performing a UNION in postgresql. This bug was fixed in Laravel
     * v5.7.
     *
     * https://github.com/laravel/framework/pull/27589
     *
     * Once we can upgrade to that version, this wrapper should
     * be removed in favor of the QueryBuilder::get() approach used
     * in other QueryBuilders.
     *
     * @return array [
     * 	@option string The compiled query string
     * 	@option array The merged bindings (key/value)
     * ]
     */
    public function getCompiledQuery()
    {
        $this->setCommonColumns();

        $defaultQueryObject = $this->getDefault();

        // Use a UNION to ensure the query will match rows in email_templates and
        // email_templates_default. This ensures that custom templates which have
        // no default in email_templates_default are still returned. These templates
        // should not be returned when a role filter is used.
        if (empty($this->fromRoleIds) && empty($this->toRoleIds)) {
            $customQueryObject = $this->getCustom();
            return [
                '(' . $defaultQueryObject->toSql() . ') union (' . $customQueryObject->toSql() . ')',
                $defaultQueryObject->mergeBindings($customQueryObject)->getBindings(),
            ];
        }

        return [
            $defaultQueryObject->toSql(),
            $defaultQueryObject->getBindings(),
        ];
    }

    /**
     * Retrieve all matches from query builder limited by those
     * which are custom templates or have been modified from the
     * default.
     *
     * Default templates that have not been modified have no entry
     * in the email_templates table and so `et.email_id` is null.
     *
     * @return QueryObject
     */
    public function getModified()
    {
        $this->setCommonColumns();
        $q = $this->getCustom();
        $q->whereNotNull('et.email_id');
        return $q;
    }

    /**
     * Set the columns that should be returned for most requests
     */
    protected function setCommonColumns()
    {
        $this->columns = [
            'etd.can_disable',
            'etd.can_edit',
            DB::raw('COALESCE(etd.email_key, et.email_key) as email_key'),
            'etd.from_role_id',
            'etd.to_role_id',
            'etd.stage_id',
            'et.email_id',
            'et.context_id',
            DB::raw('COALESCE(et.enabled, 1) as enabled'),
        ];
    }

    /**
     * Execute query builder for default email templates
     *
     * @see self::getCompiledQuery()
     *
     * @return QueryObject
     */
    protected function getDefault()
    {
        $q = DB::table('email_templates_default as etd')
            ->orderBy('email_key', 'asc');

        if (!is_null($this->contextId)) {
            $contextId = $this->contextId;
            $q->leftJoin('email_templates as et', function ($table) use ($contextId) {
                $table->on('etd.email_key', '=', 'et.email_key')
                    ->on('et.context_id', '=', DB::raw((int) $contextId));
            });
        } else {
            $q->leftJoin('email_templates as et', 'etd.email_key', '=', 'et.email_key');
        }

        if (!is_null($this->contextId)) {
            $contextId = $this->contextId;
            $q->where(function ($q) use ($contextId) {
                $q->whereNull('et.context_id')
                    ->orWhere('et.context_id', '=', $this->contextId);
            });
        }

        if (!empty($this->isEnabled)) {
            $q->where(function ($q) {
                // Unmodified default templates are considered enabled
                $q->whereNull('et.enabled')
                    ->orWhere('et.enabled', '=', 1);
            });
        } elseif ($this->isEnabled === false) {
            $q->where('et.enabled', '!=', 1);
        }

        if (!empty($this->isCustom)) {
            $q->whereNull('etd.can_disable');
        } elseif ($this->isCustom === false) {
            $q->whereNotNull('etd.can_disable');
        }

        if (!empty($this->fromRoleIds)) {
            $q->whereIn('etd.from_role_id', $this->fromRoleIds);
        }

        if (!empty($this->toRoleIds)) {
            $q->whereIn('etd.to_role_id', $this->toRoleIds);
        }

        if (!empty($this->stageIds)) {
            if (in_array(\PKP\services\PKPEmailTemplateService::EMAIL_TEMPLATE_STAGE_DEFAULT, $this->stageIds)) {
                $q->whereNull('etd.stage_id')
                    ->orWhereIn('etd.stage_id', $this->stageIds);
            } else {
                $q->whereIn('etd.stage_id', $this->stageIds);
            }
        }

        // search phrase
        $q->when($this->searchPhrase !== null, function ($query) {
            $words = explode(' ', $this->searchPhrase);
            $rawClause = DB::raw("CONCAT('%', LOWER(?), '%')");
            foreach ($words as $word) {
                $query->whereIn('et.email_id', function ($query) use ($word, $rawClause) {
                    return $query->select('email_id')
                        ->from('email_templates_settings')
                        ->whereIn('setting_name', ['subject', 'body'])
                        ->where(DB::raw('LOWER(setting_value)'), 'LIKE', $rawClause)->addBinding($word);
                })->orWhereIn('etd.email_key', function ($query) use ($word, $rawClause) {
                    return $query->select('email_key')
                        ->from('email_templates_default_data')
                        ->where(DB::raw('LOWER(subject)'), 'LIKE', $rawClause)->addBinding($word)
                        ->orWhere(DB::raw('LOWER(body)'), 'LIKE', $rawClause)->addBinding($word)
                        ->orWhere(DB::raw('LOWER(description)'), 'LIKE', $rawClause)->addBinding($word);
                })->orWhere(DB::raw('LOWER(et.email_key)'), 'LIKE', $rawClause)->addBinding($word);
            }
        });

        if (!empty($this->keys)) {
            $keys = $this->keys;
            $q->where(function ($q) use ($keys) {
                $q->whereIn('etd.email_key', $this->keys)
                    ->orWhereIn('et.email_key', $this->keys);
            });
        }

        if (!empty($this->toRoleIds)) {
            $q->whereIn('etd.to_role_id', $this->toRoleIds);
        }

        // Add app-specific query statements
        \HookRegistry::call('EmailTemplate::getMany::queryObject::default', [&$q, $this]);

        $q->select($this->columns);

        return $q;
    }

    /**
     * Execute query builder for custom email templates
     * and email templates that have been modified from
     * the default.
     *
     * @see self::getCompiledQuery()
     *
     * @return QueryObject
     */
    protected function getCustom()
    {
        $q = DB::table('email_templates as et')
            ->leftJoin('email_templates_default as etd', 'etd.email_key', '=', 'et.email_key');

        if (!is_null($this->contextId)) {
            $q->where(function ($q) {
                $q->whereNull('et.context_id')
                    ->orWhere('et.context_id', '=', $this->contextId);
            });
        }

        if (!empty($this->isEnabled)) {
            $q->where('et.enabled', '=', 1);
        } elseif ($this->isEnabled === false) {
            $q->where('et.enabled', '!=', 1);
        }

        if (!empty($this->isCustom)) {
            $q->whereNull('etd.can_disable');
        } elseif ($this->isCustom === false) {
            $q->whereNotNull('etd.can_disable');
        }

        if (!empty($this->searchPhrase)) {
            $words = explode(' ', $this->searchPhrase);
            if (count($words)) {
                $q->leftJoin('email_templates_settings as ets', 'et.email_id', '=', 'ets.email_id');
                foreach ($words as $word) {
                    $word = strtolower(addcslashes($word, '%_'));
                    $q->where(function ($q) use ($word) {
                        $q->where(DB::raw('lower(et.email_key)'), 'LIKE', "%{$word}%")
                            ->orWhere(function ($q) use ($word) {
                                $q->where('ets.setting_name', 'subject');
                                $q->where(DB::raw('lower(ets.setting_value)'), 'LIKE', "%{$word}%");
                            })
                            ->orWhere(function ($q) use ($word) {
                                $q->where('ets.setting_name', 'body');
                                $q->where(DB::raw('lower(ets.setting_value)'), 'LIKE', "%{$word}%");
                            });
                    });
                }
            }
        }

        if (!empty($this->keys)) {
            $q->where(function ($q) {
                $q->whereIn('etd.email_key', $this->keys)
                    ->orWhereIn('et.email_key', $this->keys);
            });
        }

        // Add app-specific query statements
        \HookRegistry::call('EmailTemplate::getMany::queryObject::custom', [&$q, $this]);

        $q->select($this->columns);

        return $q;
    }
}
