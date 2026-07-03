<?php

namespace App\Models\Scopes;

use App\Support\Maturity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides adult-rated content (18+/20+) from KIDS profiles — including direct /watch/{slug} access,
 * since it also filters route-model binding. Keys off the active profile set by EnsureProfileSelected
 * (a per-request attribute). Admin and the mobile API never set that attribute, so they're unaffected;
 * an adult profile still sees adult titles (with the Pro gate applied at watch time).
 */
class MaturityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! app()->bound('request')) {
            return;
        }

        $profile = app('request')->attributes->get('profile');
        if (! $profile || ! $profile->is_kids) {
            return;
        }

        $col = $model->getTable().'.maturity';
        $builder->where(fn ($w) => $w->whereNull($col)->orWhereNotIn($col, Maturity::ADULT));
    }
}
