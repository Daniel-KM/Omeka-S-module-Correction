<?php
namespace Correction\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class CorrectionAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'resource' => 'resource',
        'token' => 'token',
        'email' => 'email',
        'reviewed' => 'reviewed',
        'created' => 'created',
        'modified' => 'modified',
    ];

    public function getResourceName()
    {
        return 'corrections';
    }

    public function getRepresentationClass()
    {
        return \Correction\Api\Representation\CorrectionRepresentation::class;
    }

    public function getEntityClass()
    {
        return \Correction\Entity\Correction::class;
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore)
    {
        // TODO Use shouldHydrate() and validateEntity().
        /** @var \Correction\Entity\Correction $entity */
        $data = $request->getContent();
        if (Request::CREATE === $request->getOperation()) {
            $resource = $this->getAdapter('resources')->findEntity($data['o:resource']['o:id']);
            $token = empty($data['o-module-correction:token'])
                ? null
                : $this->getAdapter('correction_tokens')->findEntity($data['o-module-correction:token']['o:id']);
            $email = empty($data['o:email']) ? null : $data['o:email'];
            $reviewed = !empty($data['o-module-correction:reviewed']);
            $proposal = empty($data['o-module-correction:proposal'])
                ? []
                : $data['o-module-correction:proposal'];
            $entity
                ->setResource($resource)
                ->setToken($token)
                ->setEmail($email)
                ->setReviewed($reviewed)
                ->setProposal($proposal);
            ;
        } elseif (Request::UPDATE === $request->getOperation()) {
            if ($this->shouldHydrate($request, 'o-module-correction:reviewed', $data)) {
                $reviewed = !empty($data['o-module-correction:reviewed']);
                $entity
                    ->setReviewed($reviewed);
            }
            if ($this->shouldHydrate($request, 'o-module-correction:proposal', $data)) {
                $proposal = empty($data['o-module-correction:proposal'])
                    ? []
                    : $data['o-module-correction:proposal'];
                $entity
                    ->setProposal($proposal);
            }
        }

        $this->updateTimestamps($request, $entity);
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        $isOldOmeka = \Omeka\Module::VERSION < 2;
        $alias = $isOldOmeka ? $this->getEntityClass() : 'omeka_root';
        $expr = $qb->expr();

        if (isset($query['id'])) {
            $qb->andWhere($expr->eq(
                $alias . '.id',
                $this->createNamedParameter($qb, $query['id'])
            ));
        }

        if (isset($query['resource_id'])) {
            if (!is_array($query['resource_id'])) {
                $query['resource_id'] = [$query['resource_id']];
            }
            $resourceAlias = $this->createAlias();
            $qb->innerJoin(
                $alias . '.resource',
                $resourceAlias
            );
            $qb->andWhere($expr->in(
                $resourceAlias . '.id',
                $this->createNamedParameter($qb, $query['resource_id'])
            ));
        }

        if (isset($query['token_id'])) {
            if (!is_array($query['token_id'])) {
                $query['token_id'] = [$query['token_id']];
            }
            $resourceAlias = $this->createAlias();
            $qb->innerJoin(
                $alias . '.token',
                $resourceAlias
            );
            $qb->andWhere($expr->eq(
                $resourceAlias . '.id',
                $this->createNamedParameter($qb, $query['token_id'])
            ));
        }

        if (isset($query['email'])) {
            $qb->andWhere($expr->eq(
                $alias . '.email',
                $this->createNamedParameter($qb, $query['email'])
            ));
        }

        if (isset($query['reviewed']) && is_numeric($query['reviewed'])) {
            $qb->andWhere($expr->eq(
                $alias . '.reviewed',
                $this->createNamedParameter($qb, (bool) $query['reviewed'])
            ));
        }

        // TODO Add time comparison (see modules AdvancedSearchPlus or Next).
        if (isset($query['created'])) {
            $qb->andWhere($expr->eq(
                $alias . '.created',
                $this->createNamedParameter($qb, $query['created'])
            ));
        }

        if (isset($query['modified'])) {
            $qb->andWhere($expr->eq(
                $alias . '.modified',
                $this->createNamedParameter($qb, $query['modified'])
            ));
        }
    }
}
