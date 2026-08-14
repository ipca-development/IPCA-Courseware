-- Expand users.role so admin user-create can store Instructor / Chief Instructor.
-- Production historically used enum('admin','student','supervisor').
-- instructor is kept as a compatibility value; the UI stores instructors as supervisor.

ALTER TABLE users
  MODIFY COLUMN role ENUM(
    'admin',
    'student',
    'supervisor',
    'instructor',
    'chief_instructor'
  ) NOT NULL;
