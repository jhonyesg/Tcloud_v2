## 1. Modificar ShareController

- [x] 1.1 Agregar check `$request->ajax()` en método index()
- [x] 1.2 Agregar `return view('shares.index', ['shares' => $shares])` para navegación normal

## 2. Verificar funcionamiento

- [ ] 2.1 Probar acceso directo a /shares (debe mostrar vista) - requiere login manual en navegador
- [ ] 2.2 Probar que AJAX del frontend sigue funcionando (debe retornar JSON) - requiere login manual en navegador
