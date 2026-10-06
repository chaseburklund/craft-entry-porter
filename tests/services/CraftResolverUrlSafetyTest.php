<?php

namespace chaseburklund\entryporter\tests\services;

use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\services\CraftResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests the checks on asset download URLs, which do not need a Craft app.
 *
 * Every case uses an IP address or a URL that is rejected before DNS, so the tests do not
 * need network access.
 */
final class CraftResolverUrlSafetyTest extends TestCase
{
    public function testPublicHttpsUrlIsFetchable(): void
    {
        $this->assertTrue(CraftResolver::isUrlPubliclyFetchable('https://8.8.8.8/some/file.jpg'));
    }

    public function testLoopbackIsRejected(): void
    {
        $this->assertFalse(CraftResolver::isUrlPubliclyFetchable('http://127.0.0.1/admin'));
    }

    public function testCloudMetadataLinkLocalIsRejected(): void
    {
        $this->assertFalse(CraftResolver::isUrlPubliclyFetchable('http://169.254.169.254/latest/meta-data/'));
    }

    public function testNonHttpSchemeIsRejected(): void
    {
        $this->assertFalse(CraftResolver::isUrlPubliclyFetchable('file:///etc/passwd'));
    }

    public function testRfc1918PrivateAddressIsRejected(): void
    {
        $this->assertFalse(CraftResolver::isUrlPubliclyFetchable('http://10.0.0.5/internal'));
        $this->assertFalse(CraftResolver::isUrlPubliclyFetchable('http://192.168.1.1/internal'));
    }

    /**
     * parse_url() keeps the brackets around an IPv6 literal, which must be removed before the
     * address can be checked. Without that, public IPv6 addresses would also be rejected.
     */
    public function testBracketedIpv6LiteralsAreHandled(): void
    {
        $this->assertFalse(CraftResolver::isUrlPubliclyFetchable('http://[::1]/x'), 'IPv6 loopback must be rejected');
        $this->assertTrue(CraftResolver::isUrlPubliclyFetchable('https://[2001:4860:4860::8888]/x'), 'a public IPv6 literal must not be rejected merely for being bracketed');
    }

    /**
     * PHP's private and reserved range flags treat IPv4-mapped IPv6 addresses as public
     * whatever IPv4 address they contain. Both hex and dotted spellings are included, since
     * the address is checked from its raw bytes rather than its text.
     *
     * @return array<string, array{string}>
     */
    public static function ipv4EmbeddingIpv6BypassProvider(): array
    {
        return [
            'mapped, dotted loopback' => ['http://[::ffff:127.0.0.1]/x'],
            'mapped, dotted cloud metadata' => ['http://[::ffff:169.254.169.254]/latest/meta-data/'],
            'mapped, dotted RFC1918' => ['http://[::ffff:10.0.0.5]/internal'],
            'mapped, hex loopback' => ['http://[::ffff:7f00:1]/x'],
            'mapped, hex cloud metadata' => ['http://[::ffff:a9fe:a9fe]/latest/meta-data/'],
            'compatible, dotted loopback' => ['http://[::127.0.0.1]/x'],
            'mapped, fully expanded loopback' => ['http://[0:0:0:0:0:ffff:127.0.0.1]/x'],
        ];
    }

    /** @dataProvider ipv4EmbeddingIpv6BypassProvider */
    public function testIpv6AddressesEmbeddingAPrivateIpv4AreRejected(string $url): void
    {
        $this->assertFalse(
            CraftResolver::isUrlPubliclyFetchable($url),
            "{$url} embeds a private/reserved IPv4 address and must not pass the SSRF gate",
        );
    }

    /**
     * PHP treats the shared address space 100.64.0.0/10 as public, but it is used for
     * internal addresses in many networks. Addresses just outside the range stay allowed.
     */
    public function testCgnatSharedAddressSpaceIsRejected(): void
    {
        $this->assertFalse(CraftResolver::isUrlPubliclyFetchable('http://100.64.0.1/internal'), '100.64.0.0/10 lower bound');
        $this->assertFalse(CraftResolver::isUrlPubliclyFetchable('http://100.100.100.100/internal'), 'mid-range CGNAT');
        $this->assertFalse(CraftResolver::isUrlPubliclyFetchable('http://100.127.255.255/internal'), '100.64.0.0/10 upper bound');
        $this->assertFalse(CraftResolver::isUrlPubliclyFetchable('http://[::ffff:100.64.0.1]/internal'), 'CGNAT reached via an IPv4-mapped IPv6 spelling');

        $this->assertTrue(CraftResolver::isUrlPubliclyFetchable('http://100.63.255.255/x'), 'the address just below 100.64.0.0/10 is public and must still be allowed');
        $this->assertTrue(CraftResolver::isUrlPubliclyFetchable('http://100.128.0.0/x'), 'the address just above 100.64.0.0/10 is public and must still be allowed');
    }

    /** Public IPv6 addresses, and IPv4-mapped spellings of public IPv4 addresses, are allowed. */
    public function testLegitimatePublicIpv6IsStillAllowed(): void
    {
        $this->assertTrue(CraftResolver::isUrlPubliclyFetchable('https://[2606:4700:4700::1111]/x'), 'Cloudflare public resolver, IPv6');
        $this->assertTrue(CraftResolver::isUrlPubliclyFetchable('https://[2001:4860:4860::8888]/x'), 'Google public resolver, IPv6');
        $this->assertTrue(CraftResolver::isUrlPubliclyFetchable('https://[::ffff:8.8.8.8]/x'), 'an IPv4-mapped *public* address must remain fetchable');
    }

    public function testMalformedUrlIsRejected(): void
    {
        $this->assertFalse(CraftResolver::isUrlPubliclyFetchable('not a url at all'));
        $this->assertFalse(CraftResolver::isUrlPubliclyFetchable(''));
    }

    /** Calls the private isUrlSafeToFetch() through reflection. */
    private function invokeIsUrlSafeToFetch(CraftResolver $resolver, string $url, Report $report): bool
    {
        $method = new ReflectionMethod($resolver, 'isUrlSafeToFetch');
        $method->setAccessible(true);
        return $method->invoke($resolver, $url, $report, 'test-file.jpg');
    }

    public function testUnsafeUrlIsRejectedRegardlessOfSourceOrigin(): void
    {
        $resolver = new CraftResolver();
        $resolver->sourceOrigin = 'http://127.0.0.1';
        $report = new Report();

        $this->assertFalse($this->invokeIsUrlSafeToFetch($resolver, 'http://127.0.0.1/x', $report));
        $this->assertNotSame([], $report->warnings);
    }

    /** A URL on a different host from the source origin is fetched, and the difference reported. */
    public function testHostMismatchIsAllowedAndReported(): void
    {
        $resolver = new CraftResolver();
        $resolver->sourceOrigin = 'https://api.example.com';
        $report = new Report();

        $result = $this->invokeIsUrlSafeToFetch($resolver, 'https://8.8.8.8/foo.jpg', $report);

        $this->assertTrue($result, 'a public URL on a different host than the source origin must still be fetched');
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('8.8.8.8', $report->warnings[0]);
        $this->assertStringContainsString('api.example.com', $report->warnings[0]);
    }

    public function testMatchingOriginProducesNoWarning(): void
    {
        $resolver = new CraftResolver();
        $resolver->sourceOrigin = 'https://8.8.8.8';
        $report = new Report();

        $result = $this->invokeIsUrlSafeToFetch($resolver, 'https://8.8.8.8/foo.jpg', $report);

        $this->assertTrue($result);
        $this->assertSame([], $report->warnings);
    }

    public function testNullSourceOriginIsReportedAsAMismatchButStillAllowed(): void
    {
        $resolver = new CraftResolver();
        // sourceOrigin left at its null default.
        $report = new Report();

        $result = $this->invokeIsUrlSafeToFetch($resolver, 'https://8.8.8.8/foo.jpg', $report);

        $this->assertTrue($result);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('none was set', $report->warnings[0]);
    }
}
