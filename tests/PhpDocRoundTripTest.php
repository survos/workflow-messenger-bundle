<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ConstructorExtractor;
use Symfony\Component\PropertyInfo\Extractor\PhpStanExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class PhpDocRoundTripTest extends TestCase
{
    public function testPlainContextAndSubjectSurviveBothTransports(): void
    {
        $request = new \Survos\WorkflowMessengerBundle\Message\TransitionRequest(
            'asset', new \Survos\WorkflowMessengerBundle\Message\SubjectReference('asset', '123'), 'analyze',
            ['cascade' => 'sync', 'nested' => ['enabled' => true, 'nothing' => null, 'ids' => [2 => 'a', 7 => 'b']]],
            'request-1',
        );
        $native = new \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer();
        $copy = $native->decode($native->encode(new \Symfony\Component\Messenger\Envelope($request)))->getMessage();
        self::assertEquals($request, $copy);
        $serializer = $this->serializer();
        $copy = $serializer->deserialize($serializer->serialize($request, 'json'), $request::class, 'json');
        self::assertEquals($request, $copy);
        self::assertInstanceOf(\Survos\WorkflowMessengerBundle\Message\SubjectReference::class, $copy->subject);
    }

    public function testBlockerReasonsRoundTrip(): void
    {
        $result = new \Survos\WorkflowMessengerBundle\Message\TransitionResult('request-1', 'blocked', ['Missing source', 'Not ready']);
        $serializer = $this->serializer();
        self::assertEquals($result, $serializer->deserialize($serializer->serialize($result, 'json'), $result::class, 'json'));
    }

    public function testBlockerReasonsRejectObjects(): void
    {
        $serializer = $this->serializer();
        $this->expectException(\Symfony\Component\Serializer\Exception\NotNormalizableValueException::class);
        $serializer->denormalize(['requestId' => 'request-1', 'status' => 'blocked', 'reasons' => [['message' => 'invalid']]], \Survos\WorkflowMessengerBundle\Message\TransitionResult::class);
    }

    private function serializer(): Serializer
    {
        // These are integration checks for consuming apps with PHPDoc-aware serialization enabled.
        if (!class_exists(PhpStanExtractor::class)
            || !class_exists(\PHPStan\PhpDocParser\Parser\PhpDocParser::class)
            || !class_exists(\phpDocumentor\Reflection\Types\ContextFactory::class)
            || !class_exists(Serializer::class)) {
            self::markTestSkipped('Run with a consuming app providing Serializer, PropertyInfo and the PHPDoc parsers.');
        }

        $phpDoc = new PhpStanExtractor();
        $reflection = new ReflectionExtractor();
        $types = new PropertyInfoExtractor([], [new ConstructorExtractor([$phpDoc]), $phpDoc, $reflection]);

        return new Serializer([
            new ObjectNormalizer(new ClassMetadataFactory(new AttributeLoader()), propertyTypeExtractor: $types),
            new ArrayDenormalizer(),
        ], [new JsonEncoder()]);
    }
}
