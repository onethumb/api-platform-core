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

namespace ApiPlatform\Core\Tests\Bridge\Symfony\Bundle\Command;

use ApiPlatform\Core\Bridge\Symfony\Bundle\Command\SwaggerCommand;
use ApiPlatform\Documentation\Documentation;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceNameCollection;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class SwaggerCommandUnitTest extends KernelTestCase
{
    use ExpectDeprecationTrait;
    /** @var MockObject&NormalizerInterface */
    private $normalizer;

    /** @var ResourceNameCollectionFactoryInterface&MockObject */
    private $resources;

    /** @var SwaggerCommand */
    private $command;

    protected function setUp(): void
    {
        $this->normalizer = $this->createMock(NormalizerInterface::class);
        $this->resources = $this->createMock(ResourceNameCollectionFactoryInterface::class);
        $this->command = new SwaggerCommand(
            $this->normalizer,
            $this->resources,
            'My API',
            'I told you already: it is my API',
            'one-zero-zero'
        );

        $this->resources->method('create')
            ->willReturn(new ResourceNameCollection());
    }

    /**
     * @group legacy
     */
    public function testDocumentationJsonDoesNotUseEscapedSlashes(): void
    {
        $this->expectDeprecation('The command "api:swagger:export" is using pre-2.7 metadata, new metadata will not appear, use "api:openapi:export" instead.');
        $this->normalizer->method('normalize')
            ->with(self::isInstanceOf(Documentation::class))
            ->willReturn(['a-jsonable-documentation' => 'containing/some/slashes']);

        $output = new BufferedOutput();

        $this->command->run(new ArrayInput([]), $output);

        $jsonOutput = $output->fetch();

        self::assertJson($jsonOutput);
        self::assertStringNotContainsString('containing\/some\/slashes', $jsonOutput);
        self::assertStringContainsString('containing/some/slashes', $jsonOutput);
    }
}