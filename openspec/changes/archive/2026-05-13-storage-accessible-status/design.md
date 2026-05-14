## Architecture

### Backend

#### Migración
```php
// Nuevas columnas en storage_providers
$table->boolean('is_accessible')->default(false);
$table->timestamp('last_checked_at')->nullable();
```

#### Modelo StorageProvider
```php
protected $casts = [
    'config' => 'array',
    'enabled' => 'boolean',
    'is_accessible' => 'boolean',
    'last_checked_at' => 'datetime',
];
```

#### Endpoint test (StorageProviderController)
El método `test()` existente ya realiza la verificación de conectividad. Se modifica para guardar el resultado:
```php
$storage->update([
    'is_accessible' => $result['success'],
    'last_checked_at' => now(),
]);
```

#### Endpoint /user/storages (FileController)
Se agregan los campos a la respuesta JSON:
```php
return response()->json([
    'storages' => $storages->map(fn($s) => [
        'id' => $s->id,
        'name' => $s->name,
        'type' => $s->type,
        'permissions' => $s->pivot->permissions,
        'can_create_shares' => (bool) $s->pivot->can_create_shares,
        'accessible' => $s->is_accessible,
        'last_checked' => $s->last_checked_at?->diffForHumans(),
    ]),
]);
```

### Frontend

#### Estructura de datos Alpine.js
```javascript
storageSearchQuery: '',
storageSortField: 'name',
storageSortDirection: 'asc',
```

#### Función filteredStorages()
```javascript
filteredStorages() {
    let storages = [...this.availableStorages];
    
    // Filtro por búsqueda
    if (this.storageSearchQuery) {
        const q = this.storageSearchQuery.toLowerCase();
        storages = storages.filter(s => s.name.toLowerCase().includes(q));
    }
    
    // Ordenamiento
    storages.sort((a, b) => {
        let valA = a[this.storageSortField];
        let valB = b[this.storageSortField];
        if (typeof valA === 'string') valA = valA.toLowerCase();
        if (typeof valB === 'string') valB = valB.toLowerCase();
        if (valA < valB) return this.storageSortDirection === 'asc' ? -1 : 1;
        if (valA > valB) return this.storageSortDirection === 'asc' ? 1 : -1;
        return 0;
    });
    
    return storages;
}
```

#### HTML Tabla
```html
<!-- Buscador -->
<input type="text" x-model="storageSearchQuery" placeholder="Buscar storage...">

<!-- Tabla -->
<table>
    <thead>
        <tr>
            <th @click="toggleStorageSort('name')">Nombre</th>
            <th @click="toggleStorageSort('permissions')">Permisos</th>
            <th @click="toggleStorageSort('accessible')">Accesible</th>
        </tr>
    </thead>
    <tbody>
        <template x-for="storage in filteredStorages()">
            <tr @click="enterStorage(storage.id, storage.name)">
                <td x-text="storage.name"></td>
                <td x-text="storage.permissions"></td>
                <td>
                    <span :class="storage.accessible ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'">
                        <span x-text="storage.accessible ? 'Accesible' : 'No Accesible'"></span>
                    </span>
                    <span x-text="storage.last_checked" class="text-xs text-gray-400"></span>
                </td>
            </tr>
        </template>
    </tbody>
</table>
```
