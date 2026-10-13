INSERT INTO settings(key,value,updated_at)
VALUES('invoice',json_object('registrationNumber','516067873','registrationNumberScope','invoice'),strftime('%Y-%m-%dT%H:%M:%fZ','now'))
ON CONFLICT(key) DO UPDATE SET
value=json_set(settings.value,'$.registrationNumber','516067873','$.registrationNumberScope','invoice'),
updated_at=excluded.updated_at;
