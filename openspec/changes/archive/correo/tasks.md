## 1. Base Module Setup

- [x] 1.1 Create module directory structure `/src/modules/correo/`
- [x] 1.2 Add nodemailer dependency
- [x] 1.3 Create database migration for correo_config table
- [x] 1.4 Create database migration for correo_plantillas table
- [x] 1.5 Create database migration for correo_log table

## 2. Config Service

- [x] 2.1 Implement CorreoConfig model
- [x] 2.2 Implement ConfigService with encrypt/decrypt password
- [x] 2.3 Add API endpoints for config CRUD
- [x] 2.4 Add SMTP connection test endpoint

## 3. Plantillas Service

- [x] 3.1 Implement CorreoPlantilla model
- [x] 3.2 Implement PlantillaService for template management
- [x] 3.3 Add API endpoints for plantillas CRUD
- [x] 3.4 Create seed for default templates

## 4. Notification Service

- [x] 4.1 Implement NotificationService with send method
- [x] 4.2 Add variable replacement logic (Handlebars)
- [x] 4.3 Implement logging to correo_log
- [x] 4.4 Add retry logic for failed sends

## 5. Integration Points

- [x] 5.1 Add "enviar por correo" option to shared link feature
- [x] 5.2 Add notification option when creating users
- [x] 5.3 Add password recovery email trigger

## 6. Admin UI

- [x] 6.1 Create SMTP configuration form in admin panel
- [x] 6.2 Create plantillas management interface
- [x] 6.3 Add correo log viewer in admin

## 7. Testing

- [x] 7.1 Unit tests for ConfigService (encrypt/decrypt)
- [x] 7.2 Unit tests for variable replacement
- [x] 7.3 Integration test for email sending
