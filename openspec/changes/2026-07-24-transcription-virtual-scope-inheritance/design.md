## Design: Herencia virtual de transcripciones

### Modelo de datos (sin cambios)

```
storage_providers (id, name, base_path, transcription_enabled, folder_layout, allow_parent_overlap)
  │
  ├── 47: 01 Emisoras 01       base_path=/data/Tcloud/Disco_A/television/Radios/Bogota  layout=grouped_by_subfolder
  │    └── 63: 03 La W Bogota  base_path=/data/Tcloud/Disco_A/television/Radios/Bogota/LA_W
  │
  └── 49: 02 Emisoras 01 Reg   base_path=/data/Tcloud/Disco_I/television/RD1  layout=grouped_by_subfolder
       └── (varios hijos específicos)

storage_providers (47) ──< files (469xx) ──< transcriptions (??)
storage_providers (63) ──< files (5xxx)  ──< transcriptions (645)
```

### Scope virtual

```
resolveInheritedTranscriptionScope(storageId):
  ids = [storageId]
  queue = [storageId]
  while queue not empty:
    current = queue.pop()
    descendants = StorageProvider::transcriptionEnabled()
      .where('id', '!=', current)
      .where('base_path', 'LIKE', storage[current].base_path + '/%')
      .where('allow_parent_overlap', false)
      .pluck('id', 'base_path')
    for each descendant:
      if descendant.id not in ids:
        ids.push(descendant.id)
        queue.push(descendant.id)
  return ids
```

Notas:
- Solo expande si hay descendientes con `transcription_enabled=true`.
- Sin recursión infinita: cada storage aparece una sola vez (chequeo `if not in ids`).
- El storage padre puede tener `transcription_enabled=false` y aún ver lo de sus hijos. Es un caso de uso válido: "este storage se asignó a un cliente que quiere ver el consolidado, pero la transcripción la hace el operador desde el storage hijo".

### API

**GET /api/transcriptor/storage-files?storage_id=47&mode=today**

Response:
```json
{
  "storage": { "id": 47, "name": "01 Emisoras 01", "transcription_enabled": true },
  "scope": {
    "self": { "id": 47, "name": "01 Emisoras 01" },
    "descendants": [
      { "id": 63, "name": "03 La W Bogota" }
    ],
    "storage_ids": [47, 63]
  },
  "files": [
    { "id": 4558857, "name": "caracol_xxx.mp4", "parent_id": 12, "folder_name": "Caracol", "has_transcription": true, "source_storage_id": 47 },
    { "id": 4944119, "name": "wradio_xxx.mp3", "parent_id": 33, "folder_name": "LA_W", "has_transcription": true, "source_storage_id": 63 }
  ],
  "transcribed_count": 2075
}
```

### Capa de aplicación

- **Modelo**: `StorageProvider::withTranscriptionDescendants(int $rootId): array<int>` retorna array de IDs.
- **Controlador**: `ApiTranscriptorController::storageFiles()` aplica el scope en sus queries de File/Transcription.
- **Vista**: badge "N hijos" en la lista de storages + tooltip sobre la herencia.

### Preservación de invariantes

1. **No se crean nuevas filas en transcriptions** — la herencia es puramente una capa de lectura.
2. **No se rompe el scanner actual** — `DiskScannerService` sigue computando exclusion lists.
3. **No afecta al tick automático** — `transcription:tick` sigue trabajando con storage IDs reales.
4. **El batch dispatch sigue funcionando** — se procesa desde el storage real dueño del File.
