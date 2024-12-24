<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\GraphQl\State\Processor;

use ApiPlatform\GraphQl\Resolver\Util\IdentifierTrait;
use ApiPlatform\GraphQl\Serializer\ItemNormalizer;
use ApiPlatform\GraphQl\Serializer\SerializerContextBuilderInterface;
use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\GraphQl\Mutation;
use ApiPlatform\Metadata\GraphQl\Operation as GraphQlOperation;
use ApiPlatform\Metadata\GraphQl\Subscription;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\HasNextPagePaginatorInterface;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\Pagination\PartialPaginatorInterface;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Transforms the data to a GraphQl json format. It uses the Symfony Normalizer then performs changes according to the type of operation.
 */
final class NormalizeProcessor implements ProcessorInterface
{
    use IdentifierTrait;

    public function __construct(private readonly NormalizerInterface $normalizer, private readonly SerializerContextBuilderInterface $serializerContextBuilder, private readonly Pagination $pagination)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?array
    {
        if (!$operation instanceof GraphQlOperation) {
            return $data;
        }

        return $this->getData($data, $operation, $uriVariables, $context);
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function getData(mixed $itemOrCollection, GraphQlOperation $operation, array $uriVariables = [], array $context = []): ?array
    {
        if (!($operation->canSerialize() ?? true)) {
            if ($operation instanceof CollectionOperationInterface) {
                if ($this->pagination->isGraphQlEnabled($operation, $context)) {
                    return 'cursor' === $this->pagination->getGraphQlPaginationType($operation) ?
                        $this->getDefaultCursorBasedPaginatedData() :
                        $this->getDefaultPageBasedPaginatedData();
                }

                return [];
            }

            if ($operation instanceof Mutation) {
                return $this->getDefaultMutationData($context);
            }

            if ($operation instanceof Subscription) {
                return $this->getDefaultSubscriptionData($context);
            }

            return null;
        }

        $normalizationContext = $this->serializerContextBuilder->create($operation->getClass(), $operation, $context, normalization: true);

        $data = null;
        if (!$operation instanceof CollectionOperationInterface) {
            if ($operation instanceof Mutation && $operation instanceof DeleteOperationInterface) {
                $data = ['id' => $this->getIdentifierFromOperation($operation, $context['args'] ?? [])];
            } else {
                $data = $this->normalizer->normalize($itemOrCollection, ItemNormalizer::FORMAT, $normalizationContext);
            }
        }

        if ($operation instanceof CollectionOperationInterface && is_iterable($itemOrCollection)) {
            if (!$this->pagination->isGraphQlEnabled($operation, $context)) {
                $data = [];
                foreach ($itemOrCollection as $index => $object) {
                    $data[$index] = $this->normalizer->normalize($object, ItemNormalizer::FORMAT, $normalizationContext);
                }
            } else {
                $data = 'cursor' === $this->pagination->getGraphQlPaginationType($operation) ?
                    $this->serializeCursorBasedPaginatedCollection($itemOrCollection, $normalizationContext, $context) :
                    $this->serializePageBasedPaginatedCollection($itemOrCollection, $normalizationContext, $context);
            }
        }

        if (null !== $data && !\is_array($data)) {
            throw new \UnexpectedValueException('Expected serialized data to be a nullable array.');
        }

        $isMutation = $operation instanceof Mutation;
        $isSubscription = $operation instanceof Subscription;
        if ($isMutation || $isSubscription) {
            $wrapFieldName = lcfirst($operation->getShortName());

            return [$wrapFieldName => $data] + ($isMutation ? $this->getDefaultMutationData($context) : $this->getDefaultSubscriptionData($context));
        }

        return $data;
    }

    /**
     * @throws \LogicException
     * @throws \UnexpectedValueException
     */
    private function serializeCursorBasedPaginatedCollection(iterable $collection, array $normalizationContext, array $context): array
    {
        $args = $context['args'];

        if (!($collection instanceof PartialPaginatorInterface)) {
            throw new \LogicException(\sprintf('Collection returned by the collection data provider must implement %s or %s.', PaginatorInterface::class, PartialPaginatorInterface::class));
        }

        $selection = $context['info']->getFieldSelection(1);

        $offset = 0;
        $totalItems = 1; // For partial pagination, always consider there is at least one item.
        $data = ['edges' => []];
        if (isset($selection['pageInfo']) || isset($selection['totalCount']) || isset($selection['edges']['cursor'])) {
            $nbPageItems = $collection->count();
            if (isset($args['after'])) {
                $after = base64_decode($args['after'], true);
                if (false === $after || '' === $args['after']) {
                    throw new \UnexpectedValueException('' === $args['after'] ? 'Empty cursor is invalid' : \sprintf('Cursor %s is invalid', $args['after']));
                }
                $offset = 1 + (int) $after;
            }

            if ($collection instanceof PaginatorInterface && (isset($selection['pageInfo']) || isset($selection['totalCount']))) {
                $totalItems = $collection->getTotalItems();
                if (isset($args['before'])) {
                    $before = base64_decode($args['before'], true);
                    if (false === $before || '' === $args['before']) {
                        throw new \UnexpectedValueException('' === $args['before'] ? 'Empty cursor is invalid' : \sprintf('Cursor %s is invalid', $args['before']));
                    }
                    $offset = (int) $before - $nbPageItems;
                }
                if (isset($args['last']) && !isset($args['before'])) {
                    $offset = $totalItems - $args['last'];
                }
            }

            $offset = max(0, $offset);


            $data = $this->getDefaultCursorBasedPaginatedData();
            if ((isset($selection['pageInfo']) || isset($selection['totalCount'])) && $totalItems > 0) {
                isset($selection['pageInfo']['startCursor']) && $data['pageInfo']['startCursor'] = base64_encode((string) $offset);
                $end = $offset + $nbPageItems - 1;
                isset($selection['pageInfo']['endCursor']) && $data['pageInfo']['endCursor'] = base64_encode((string) max($end, 0));
                isset($selection['pageInfo']['hasPreviousPage']) && $data['pageInfo']['hasPreviousPage'] = $offset > 0;

                // BEGIN PATCH
                // This is a patch to support partial pagination with hasNextPage and totalCount.
                //
                // For partial pagination responses, the total count and whether there’s a
                // next page can’t be known all of the time for large data sets
                // (search query results, large table scans, etc).
                //
                // But sometimes we _can_ infer those signals and include them, making the
                // responses more useful.
                //
                // Note: graphql-php currently enforces an INT on ‘totalCount’ but reading
                // the GraphQL spec, I think it _should_ be optional. And omitting it seems
                // more clear than returning a 0. (Returning a huge number, indicating
                // “bottomless”, like the first 7 digits of pi :), also seems better, but
                // YMMV).
                if ($collection instanceof PartialPaginatorInterface) {
                    // with partial pagination, it's possible to detect whether there _might_ be another page coming based
                    // on the number of items returned vs the max items per page, which is useful more often than not
                    $itemsPerPage = $collection->getItemsPerPage();
                    $data['pageInfo']['hasNextPage'] = $nbPageItems >= $itemsPerPage;
                    // if the last page has been reached, we can include the total item count... if not, it'd be nice to
                    // omit 'totalCount' since a value of 0 is both wrong and confusing.
                    // TODO: Investigate patching graphql-php to support NULL for totalCount, rather than just INT
                    // until then, indicate that it's "bottomless" another way if the last page hasn't been reached,
                    // otherwise include the true count.
                    $data['totalCount'] = $data['pageInfo']['hasNextPage']
                        ? 3141592 // should be NULL if graphql-php is patched to support it, but currently requires INT
                        : $offset + $nbPageItems;
                }
                // END PATCH

                if ($collection instanceof PaginatorInterface) {
                    isset($selection['totalCount']) && $data['totalCount'] = $totalItems;

                    $itemsPerPage = $collection->getItemsPerPage();
                    isset($selection['pageInfo']['hasNextPage']) && $data['pageInfo']['hasNextPage'] = (float) ($itemsPerPage > 0 ? $offset % $itemsPerPage : $offset) + $itemsPerPage * $collection->getCurrentPage() < $totalItems;
                }
            }
        }

        $index = 0;
        foreach ($collection as $object) {
            $edge = [
                'node' => $this->normalizer->normalize($object, ItemNormalizer::FORMAT, $normalizationContext),
            ];
            if (isset($selection['edges']['cursor'])) {
                $edge['cursor'] = base64_encode((string) ($index + $offset));
            }
            $data['edges'][$index] = $edge;
            ++$index;
        }

        return $data;
    }

    /**
     * @throws \LogicException
     */
    private function serializePageBasedPaginatedCollection(iterable $collection, array $normalizationContext, array $context): array
    {
        $data = ['collection' => []];

        $selection = $context['info']->getFieldSelection(1);
        if (isset($selection['paginationInfo'])) {
            $data['paginationInfo'] = [];
            if (isset($selection['paginationInfo']['itemsPerPage'])) {
                if (!($collection instanceof PartialPaginatorInterface)) {
                    throw new \LogicException(\sprintf('Collection returned by the collection data provider must implement %s to return itemsPerPage field.', PartialPaginatorInterface::class));
                }
                $data['paginationInfo']['itemsPerPage'] = $collection->getItemsPerPage();
            }
            if (isset($selection['paginationInfo']['totalCount'])) {
                if (!($collection instanceof PaginatorInterface)) {
                    throw new \LogicException(\sprintf('Collection returned by the collection data provider must implement %s to return totalCount field.', PaginatorInterface::class));
                }
                $data['paginationInfo']['totalCount'] = $collection->getTotalItems();
            }
            if (isset($selection['paginationInfo']['lastPage'])) {
                if (!($collection instanceof PaginatorInterface)) {
                    throw new \LogicException(\sprintf('Collection returned by the collection data provider must implement %s to return lastPage field.', PaginatorInterface::class));
                }
                $data['paginationInfo']['lastPage'] = $collection->getLastPage();
            }
            if (isset($selection['paginationInfo']['hasNextPage'])) {
                if (!($collection instanceof HasNextPagePaginatorInterface)) {
                    throw new \LogicException(\sprintf('Collection returned by the collection data provider must implement %s to return hasNextPage field.', HasNextPagePaginatorInterface::class));
                }
                $data['paginationInfo']['hasNextPage'] = $collection->hasNextPage();
            }
        }

        foreach ($collection as $object) {
            $data['collection'][] = $this->normalizer->normalize($object, ItemNormalizer::FORMAT, $normalizationContext);
        }

        return $data;
    }

    private function getDefaultCursorBasedPaginatedData(): array
    {
        return ['totalCount' => 0., 'edges' => [], 'pageInfo' => ['startCursor' => null, 'endCursor' => null, 'hasNextPage' => false, 'hasPreviousPage' => false]];
    }

    private function getDefaultPageBasedPaginatedData(): array
    {
        return ['collection' => [], 'paginationInfo' => ['itemsPerPage' => 0., 'totalCount' => 0., 'lastPage' => 0.]];
    }

    private function getDefaultMutationData(array $context): array
    {
        return ['clientMutationId' => $context['args']['input']['clientMutationId'] ?? null];
    }

    private function getDefaultSubscriptionData(array $context): array
    {
        return ['clientSubscriptionId' => $context['args']['input']['clientSubscriptionId'] ?? null];
    }
}
