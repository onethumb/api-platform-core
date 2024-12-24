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

namespace ApiPlatform\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;

class HALCircularReference extends ApiTestCase
{
    public function testIssue4358(): void
    {
        $r1 = self::createClient()->request('GET', '/resource_a', ['headers' => ['Accept' => 'application/hal+json']]);
        self::assertResponseIsSuccessful();
        self::assertEquals('{"_links":{"self":{"href":"\/resource_a"},"b":{"href":"\/resource_b"}},"_embedded":{"b":{"_links":{"self":{"href":"\/resource_b"},"a":{"href":"\/resource_a"}},"_embedded":{"a":{"_links":{"self":{"href":"\/resource_a"}}}}}}}', $r1->getContent());
    }
}
