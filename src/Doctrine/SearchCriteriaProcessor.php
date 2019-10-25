<?php

declare(strict_types=1);

namespace SolMaker\Doctrine;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use SolMaker\Condition\AbstractRangeCondition;
use SolMaker\Filter\Between;
use SolMaker\Filter\DateTimeRange;
use SolMaker\Filter\Equal;
use SolMaker\Filter\GreaterThan;
use SolMaker\Filter\GreaterThanOrEquals;
use SolMaker\Filter\LessThan;
use SolMaker\Filter\LessThanOrEquals;
use SolMaker\Filter\NotEqual;
use SolMaker\Search\LikeAfter;
use SolMaker\Search\LikeAround;
use SolMaker\Search\LikeBefore;
use SolMaker\SearchCriteria;

class SearchCriteriaProcessor
{
    public const DEFAULT_BUILDER_ALIAS = 'c';

    /**
     * @var string
     */
    protected $builderAlias;

    /**
     * @param SearchCriteria $criteria
     * @param EntityRepository $repository
     * @param string $builderAlias
     * @return Paginator
     */
    public function handle(
        SearchCriteria $criteria,
        EntityRepository $repository,
        $builderAlias = self::DEFAULT_BUILDER_ALIAS
    ) {
        $this->builderAlias =$builderAlias;
        $qb = $repository->createQueryBuilder($this->builderAlias);
        $this->handleFilters($criteria, $qb);
        $this->handleSearch($criteria, $qb);
        $this->handleSorting($criteria, $qb);

        return $this->pagination($criteria, $qb);
    }

    /**
     * @param SearchCriteria $criteria
     * @param QueryBuilder $qb
     */
    public function handleFilters(SearchCriteria $criteria, QueryBuilder $qb)
    {
        foreach ($criteria->getFilters() as $key => $filter) {
            $parameterStr = 'filter_'.$key;
            $field = sprintf('%s.%s', $this->builderAlias, $filter->getEntityFieldName());
            $params = sprintf(':%s', $parameterStr);

            if ($filter instanceof Equal) {
                $qb->andWhere($qb->expr()->eq($field, $params));
            } else if ($filter instanceof NotEqual) {
                $qb->andWhere($qb->expr()->neq($field, $params));
            } else if ($filter instanceof GreaterThan) {
                $qb->andWhere($qb->expr()->gt($field, $params));
            } else if ($filter instanceof GreaterThanOrEquals) {
                $qb->andWhere($qb->expr()->gte($field, $params));
            } else if ($filter instanceof LessThan) {
                $qb->andWhere($qb->expr()->lt($field, $params));
            } else if ($field instanceof LessThanOrEquals) {
                $qb->andWhere($qb->expr()->lte($field, $params));
            } else if ($filter instanceof AbstractRangeCondition) {
                $start = sprintf($params, '_start');
                $end = sprintf($params, '_end');

                if ($filter instanceof DateTimeRange) {
                    $qb->andWhere($qb->expr()->between($field, $filter->getValueStart(), $filter->getValueEnd()));
                    $qb->setParameter($start, $filter->getValueStart());
                    $qb->setParameter($end, $filter->getValueEnd());
                    continue;
                } else if ($filter instanceof Between) {
                    $qb
                        ->andWhere($qb->expr()->gte($field, $filter->getValueStart()))
                        ->andWhere($qb->expr()->lte($field, $filter->getValueEnd()));

                    $qb->setParameter($start, $filter->getValueStart(), \Doctrine\DBAL\Types\Type::DATETIME);
                    $qb->setParameter($end, $filter->getValueEnd(), \Doctrine\DBAL\Types\Type::DATETIME);
                }
            }

            $qb->setParameter($params, $filter->getValue());
        }
    }

    /**
     * @param SearchCriteria $criteria
     * @param QueryBuilder $qb
     */
    public function handleSearch(SearchCriteria $criteria, QueryBuilder $qb)
    {
        foreach ($criteria->getSearches() as $key => $search) {
            $parameterStr = 'search_'.$key;
            $field = sprintf('%s.%s', $this->builderAlias, $search->getEntityFieldName());
            $params = sprintf(':%s', $parameterStr);

            if ($search instanceof LikeAfter) {
                $value = $search->getValue().'%';
            } else if ($search instanceof LikeBefore) {
                $value = '%'.$search->getValue();
            } else if ($search instanceof LikeAround) {
                $value = '%'. $search->getValue(). '%';
            } else {
                $value = $search->getValue();
            }

            $qb
                ->andWhere($qb->expr()->like($field, $params))
                ->setParameter($params, $value);
        }
    }

    /**
     * @param SearchCriteria $criteria
     * @param QueryBuilder $qb
     */
    public function handleSorting(SearchCriteria $criteria, QueryBuilder $qb)
    {
        foreach ($criteria->getSorting() as $sorting) {
            $field = sprintf('%s.%s', $this->builderAlias, $sorting->getEntityFieldName());
            $qb
                ->addOrderBy($field, $sorting->getValue());
        }
    }

    /**
     * @param SearchCriteria $criteria
     * @param QueryBuilder $qb
     * @return Paginator
     */
    public function pagination(SearchCriteria $criteria, QueryBuilder $qb)
    {
        $paginator = new Paginator($qb);
        $page = $criteria->getPage();

        $paginator
            ->getQuery()
            ->setFirstResult($page->getOffset())
            ->setMaxResults($page->getLimit());

        return $paginator;
    }
}