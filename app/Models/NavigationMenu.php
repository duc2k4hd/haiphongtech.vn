<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NavigationMenu extends Model
{
    protected $fillable = [
        'code',
        'name',
        'location',
        'status',
        'import_batch_id',
    ];

    public function items()
    {
        return $this->hasMany(NavigationMenuItem::class, 'menu_id')->orderBy('position');
    }

    public function rootItems()
    {
        return $this->hasMany(NavigationMenuItem::class, 'menu_id')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => fn($q) => $q->where('is_active', true)->orderBy('position')])
            ->orderBy('position');
    }
}
