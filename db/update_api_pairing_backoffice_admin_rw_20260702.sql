-- Update scope Back Office: bukan superadmin/owner, tetapi admin operasional read-write.
-- Aman dijalankan berulang.
UPDATE api_pairing_requests
SET requested_scope = 'admin_rw'
WHERE requester_type = 'backoffice'
  AND requested_scope = 'superadmin';

UPDATE api_connections
SET access_scope = 'admin_rw'
WHERE remote_system_type = 'backoffice'
  AND access_scope = 'superadmin';
