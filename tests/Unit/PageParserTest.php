<?php

namespace Tests\Unit;

use App\Gaspedaal\Client;
use App\Gaspedaal\PageParser;
use App\Gaspedaal\ParseException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeGaspedaal;

class PageParserTest extends TestCase
{
    public function test_it_reads_occasions_and_paging_from_split_pushes(): void
    {
        $html = FakeGaspedaal::page([FakeGaspedaal::occasion(11), FakeGaspedaal::occasion(12)], total: 5, pageNumber: 2);

        $page = (new PageParser)->parse($html);

        $this->assertSame([11, 12], array_column($page->occasions, 'advertentieId'));
        $this->assertSame(5, $page->totalOccasions);
        $this->assertSame(2, $page->pageNumber);
        $this->assertSame(3, $page->numberOfPages);
        $this->assertSame('Touring 330e {M Sport}', $page->occasions[0]['autogegevens']['algemeen']['uitvoering']);
        $this->assertSame('Garage [x] "y"', $page->occasions[0]['aanbieder']['aanbiedergegevens']['naamsvermelding']);
        $this->assertTrue($page->ldMatches());
        $this->assertSame('https://cdn.gaspedaal.nl/images/large/11.jpg', $page->ldItems[11]['image']);
        $this->assertSame('garage-11', $page->ldItems[11]['seller_slug']);
        $this->assertNull($page->ldItems[12]['seller_slug']);
    }

    public function test_a_page_without_data_is_an_error(): void
    {
        $this->expectException(ParseException::class);

        (new PageParser)->parse('<html><body>Even geduld…</body></html>');
    }

    public function test_urls_are_normalized_and_paged(): void
    {
        $url = Client::normalizeUrl(' http://gaspedaal.nl/bmw/x3?bmin=2020&page=4 ');

        $this->assertSame('https://www.gaspedaal.nl/bmw/x3?bmin=2020', $url);
        $this->assertSame('https://www.gaspedaal.nl/bmw/x3?bmin=2020&page=2', Client::pageUrl($url, 2));
        $this->assertSame('https://www.gaspedaal.nl/volvo/v90?page=3', Client::pageUrl(Client::normalizeUrl('https://www.gaspedaal.nl/volvo/v90'), 3));
    }

    public function test_other_hosts_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Client::normalizeUrl('https://www.autotrack.nl/bmw');
    }

    public function test_portal_links_go_through_the_website_like_gaspedaal_does(): void
    {
        $url = Client::portalUrl(['klikUrl' => 'https://api.gaspedaal.nl/redirect/vehicle/207413290', 'siteNr' => 19, 'portaalType' => 'dealer'], 3);
        $this->assertSame('https://www.gaspedaal.nl/api/proxy/redirect/vehicle/207413290?app_source=react&portals_amount=3&portals_dealersite=1&sorteren=df-a', $url);

        // AutoScout24 and AutoTrack carry their siteNr.
        $this->assertStringEndsWith('portals_dealersite=0&sorteren=pr-a&siteNr=2', Client::portalUrl(['klikUrl' => 'https://api.gaspedaal.nl/redirect/vehicle/1', 'siteNr' => 2, 'portaalType' => 'other'], 1, 'pr-a'));

        $this->assertStringStartsWith('https://www.gaspedaal.nl/api/proxy/redirect/clickout/9?', Client::portalUrl(['klikUrl' => 'https://api.gaspedaal.nl/api/v1/clickouts/9', 'siteNr' => 5], 1));
        $this->assertNull(Client::portalUrl(['klikUrl' => 'https://elders.nl/x'], 1));
        $this->assertNull(Client::portalUrl([], 1));
    }
}
