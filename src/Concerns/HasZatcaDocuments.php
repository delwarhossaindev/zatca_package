<?php

namespace delwarhossaindev\Zatca\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use delwarhossaindev\Zatca\Models\ZatcaDocument;

/**
 * Add to your own invoice model to link it to its ZATCA documents.
 */
trait HasZatcaDocuments
{
    public function zatcaDocuments(): MorphMany
    {
        return $this->morphMany(ZatcaDocument::class, 'documentable');
    }

    public function zatcaDocument(): MorphOne
    {
        return $this->morphOne(ZatcaDocument::class, 'documentable')->latestOfMany();
    }
}
