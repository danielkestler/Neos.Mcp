<?php
declare(strict_types=1);

namespace Neos\Mcp\Tests\Functional;

use Neos\Flow\Tests\FunctionalTestCase;
use Neos\OpenApi\FlowAdapter\CompiledApis;
use Neos\OpenApi\Mcp\ToolCatalog;
use PHPUnit\Framework\Attributes\Test;

/**
 * The tools of the preconfigured neos server, built from the real Neos API
 */
class ToolCatalogTest extends FunctionalTestCase
{
    #[Test]
    public function everyOperationOfTheNeosApiButTheUploadsIsATool(): void
    {
        $catalog = ToolCatalog::fromCompiledApi($this->objectManager->get(CompiledApis::class)->get('neos'));

        // multipart uploads, whose body the document doesn't describe
        self::assertSame(['createAsset', 'replaceAssetFile'], array_keys($catalog->skipped));
        self::assertCount(83, $catalog);
    }
}
