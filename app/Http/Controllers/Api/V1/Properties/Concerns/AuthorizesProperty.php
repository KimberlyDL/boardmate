<?php

namespace App\Http\Controllers\Api\V1\Properties\Concerns;

use App\Authorization\PropertyAccess;
use App\Enums\PropertyAbility;
use App\Models\Property;
use Illuminate\Http\Request;

/**
 * Every property endpoint checks PropertyAccess. Someone with no relation to
 * the property gets 404 (it does not exist for them: owner data separation);
 * someone who can see it but not do this gets 403.
 */
trait AuthorizesProperty
{
    protected function authorizeProperty(Request $request, Property $property, PropertyAbility $ability): void
    {
        $user = $request->user();

        abort_unless(PropertyAccess::can($user, PropertyAbility::View, $property), 404);
        abort_unless(PropertyAccess::can($user, $ability, $property), 403, 'You do not have permission to do this for this property.');
    }
}
