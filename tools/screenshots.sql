-- Talea – sample data for the admin screenshots of the business install (tools/screenshots.sh, MySQL, prefix tl_).
-- Only what an empty install lacks and a screenshot needs. People are the sample customers of the enquiries in screenshots.sh and
-- the install's own account (Alex Morgan); prices stay placeholders (#54).

-- bookings: three services, the account as the one person who takes them, Monday to Friday 08:00–16:00, three upcoming appointments
INSERT INTO tl_booking_services (name, duration_min, buffer_min, price_text, description, active, requires_confirmation, sort_order) VALUES
  ('Site survey', 60, 30, '[price]', 'We look at the place, the machines and the paperwork and agree on the next steps.', TRUE, TRUE, 1),
  ('Maintenance consultation', 30, 15, '[price]', 'Planned servicing of existing installations: what is due and when.', TRUE, FALSE, 2),
  ('Commissioning check', 90, 30, '[price]', 'A check of a finished installation before it goes into production.', TRUE, TRUE, 3);
INSERT INTO tl_booking_staff (name, email, active, user_id, sort_order)
  SELECT name, email, TRUE, user_id, 1 FROM tl_users WHERE username = 'admin';
INSERT INTO tl_booking_staff_services (staff_id, service_id) SELECT st.id, se.id FROM tl_booking_staff st CROSS JOIN tl_booking_services se;
INSERT INTO tl_booking_hours (staff_id, service_id, weekday, time_from, time_to)
  WITH RECURSIVE d (w) AS (SELECT 1 UNION ALL SELECT w + 1 FROM d WHERE w < 5)
  SELECT st.id, NULL, d.w, '08:00', '16:00' FROM tl_booking_staff st CROSS JOIN d;
INSERT INTO tl_bookings (service_id, staff_id, starts_at, ends_at, name, email, phone, note, status, token_hash, created_at, source, language)
  SELECT se.id, st.id, b.starts_at, b.starts_at + INTERVAL se.duration_min MINUTE, b.name, b.email, b.phone, '', b.status, SHA2(CONCAT('screens', b.email), 256), NOW() - INTERVAL 2 DAY, '/contact', ''
  FROM (
    SELECT 'Site survey' AS service, TIMESTAMP(CURRENT_DATE + INTERVAL 1 DAY, '09:00:00') AS starts_at, 'Priya Shah' AS name, 'priya@example.com' AS email, '' AS phone, 'confirmed' AS status
    UNION ALL SELECT 'Maintenance consultation', TIMESTAMP(CURRENT_DATE + INTERVAL 2 DAY, '11:30:00'), 'Daniel Novak', 'daniel@example.com', '', 'confirmed'
    UNION ALL SELECT 'Commissioning check', TIMESTAMP(CURRENT_DATE + INTERVAL 3 DAY, '13:00:00'), 'Hannah Clarke', 'hannah@example.com', '+44 20 7946 0958', 'pending'
  ) b
  JOIN tl_booking_services se ON se.name = b.service CROSS JOIN tl_booking_staff st;

-- requests to the AI from the team: one new, one in progress, one done with its answer
INSERT INTO tl_requests (created_at, updated_at, author_id, title, text, about, attachments, status, done_at)
  SELECT r.created_at, r.created_at, u.user_id, r.title, r.text, '', '', r.status, r.done_at FROM tl_users u CROSS JOIN (
    SELECT NOW() - INTERVAL 3 HOUR AS created_at, 'Add our holiday opening hours to the contact page' AS title, 'Add our holiday opening hours to the contact page: [dates].' AS text, 'new' AS status, NULL AS done_at
    UNION ALL SELECT NOW() - INTERVAL 1 DAY, 'Write a news item about the new maintenance reports', 'Write a news item about our new maintenance reports and link it from the Services page.', 'in_progress', NULL
    UNION ALL SELECT NOW() - INTERVAL 3 DAY, 'Find pages without a description for search engines', 'Find pages without a description for search engines and write one for each as a draft.', 'done', NOW() - INTERVAL 2 DAY
  ) r WHERE u.username = 'admin';
INSERT INTO tl_request_messages (request_id, sender, sender_name, text, links, created_at)
  SELECT id, 'claude', 'Claude', 'Four pages had no description. I wrote one for each as a draft: About us, Services, Contact and News. Please read them and publish.', NULL, done_at
  FROM tl_requests WHERE status = 'done';

-- scheduled runs of the connected AI
INSERT INTO tl_agent_schedules (name, task, text, cadence, day, time, active, next_due, created_at) VALUES
  ('Requests from staff', 'requests', '', 'daily', 1, '07:30', TRUE, TIMESTAMP(CURRENT_DATE + INTERVAL 1 DAY, '07:30:00'), NOW() - INTERVAL 10 DAY),
  ('Site review', 'review', '', 'weekly', 1, '06:00', TRUE, TIMESTAMP(CURRENT_DATE + INTERVAL 7 - WEEKDAY(CURRENT_DATE) DAY, '06:00:00'), NOW() - INTERVAL 10 DAY),
  ('Report', 'report', '', 'monthly', 1, '08:00', TRUE, TIMESTAMP(LAST_DAY(CURRENT_DATE) + INTERVAL 1 DAY, '08:00:00'), NOW() - INTERVAL 10 DAY);
