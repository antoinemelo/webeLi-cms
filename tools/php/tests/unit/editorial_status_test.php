<?php
declare(strict_types=1);
require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/src/Application/Content/EditorialStatusResolver.php';
use App\Application\Content\EditorialStatusResolver as R;
$h = new TestHarness();
$h->assertSame('draft', R::normalize(' DRAFT '), 'status normalization');
$h->assertSame(['status'=>'published','workflow_state'=>'published'], R::pair('published'), 'canonical pair');
$h->assertSame('published', R::fromEntryRow(['id'=>1,'status'=>'published','workflow_state'=>'published']), 'consistent aggregate');
$h->expectException(fn()=>R::fromEntryRow(['id'=>1,'status'=>'published','workflow_state'=>'draft']), RuntimeException::class, 'divergence rejected');
$h->expectException(fn()=>R::normalize('deleted'), InvalidArgumentException::class, 'unknown status rejected');
$h->assertSame(['status'=>'published','workflow_state'=>'published'], R::preservePublicAggregateOnDraftSave(['status'=>'published','workflow_state'=>'published']), 'draft save preserves public aggregate');
exit($h->finish('UNIT editorial workflow'));
