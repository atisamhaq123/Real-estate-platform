UPDATE business_settings SET setting_value=REPLACE(setting_value,'Aurelle','Veyra');
UPDATE content SET title=REPLACE(title,'Aurelle','Veyra'),body=REPLACE(body,'Aurelle','Veyra');
UPDATE agent_profiles SET agency=REPLACE(agency,'Aurelle','Veyra');
UPDATE users SET name='Veyra Administrator' WHERE name='Aurelle Administrator' AND email='admin@aurelle.demo';
UPDATE notification_templates SET subject=REPLACE(subject,'Aurelle','Veyra'),body=REPLACE(body,'Aurelle','Veyra');
