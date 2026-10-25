# Progress

## Branches

| Branch | Based on | Status |
|---|---|---|
| `main` | `upstream/main` (170d889) | Fast-forwarded, pushed to `origin` |
| `feature/local-dev-setup` | `main` | Synced with upstream, pushed to `origin` |
| `feature/mvp-endpoints-1` | `feature/local-dev-setup` | Synced with upstream, pushed to `origin` |

Run the tests with `php artisan test` (in-memory SQLite, faked upload disk, faked
Stripe HTTP client; no external services). Real request/response examples are in
[API-EXAMPLES.md](API-EXAMPLES.md).

## Upstream sync (2026-10-25)

Upstream (`Andrew-Nakhle/educational_platform`) added two commits on top of our common
ancestor `244773e`:

- `8104b35` feat: add lesson progress tracking and enrollment status (2026-10-24)
- `170d889` Update backend APIs and integration documentation (2026-10-25)

### What upstream added

| Area | Change |
|---|---|
| Routes | `POST /api/auth/forgot-password`, `POST /api/auth/reset-password`, `PUT /api/auth/change-password` (auth), `POST`/`DELETE /api/student/lessons/{id}/complete` |
| Migrations | `2026_10_23_201648_create_lesson_progress_table`, `2026_10_24_124020_create_password_reset_tokens_table` |
| Models | `LessonProgress`; `User::lessonProgress()`, `Lesson::progress()`, `Course::reviews()`, `InstructorProfile::reviews()`; `User::sendPasswordResetNotification()` |
| Notifications | `ResetPasswordNotification`: mail link `{APP_URL}/reset-password?token=…&email=…` (uses `APP_URL`, not `FRONTEND_URL`) |
| Requests | `Auth\ChangePasswordRequest` (`current_password`, `new_password`, `new_password_confirmation`); `Student\GetCoursesRequest` accepts `page`, `per_page` (max 50) |
| Resources | `CourseResource`: `is_enrolled` always present, every lesson has `completed`; `LessonResource`: `video_status`, `video: null` when there is no video |
| Controllers | `StudentController::completeLesson/uncompleteLesson`; my-courses rewritten; `AuthController` forgot/reset/change password; `ReviewController` uses `validated()`; admin approve/reject return 404 when the course is missing |
| Payments | `PaymentProviderInterface::createPayment()` takes a 4th `int $courseId`; lazy Stripe client; `course_id` in metadata and redirect URLs |
| Config / env | No new config files or env keys. Password reset mail needs the existing `MAIL_*` settings, and the link uses `APP_URL` |
| Enums | Unchanged (course statuses still `draft`, `pending_publish`, `published`, `rejected`, `archived`) |

### Already implemented upstream vs. still ours only

| Item | Upstream | Notes |
|---|---|---|
| Course `status` field | Yes (already in base) | Unchanged |
| Course `is_enrolled` | **Yes** | Upstream computes it for any request with a student token. Ours preloads it with `withExists` on the student endpoints; the merged resource uses the preloaded value when present |
| Course rating fields (`rating_average`, `ratings_count`) | **No**, ours only | Upstream only added the `reviews()` relations |
| Lesson progress (complete/uncomplete, `completed` flags, my-courses `progress`, `last_lesson_id`) | **Yes** | Same URLs and response shape as ours. Upstream's routes and `StudentController` methods are kept; our `LessonProgressController` and our migration were removed |
| Ratings endpoints: list of ratings, `data { average, count, ratings[] }` | **No**, ours only | |
| Re-rating updates the rating (200) | **No**, upstream returns 409 | **Conflict**: the merge keeps our update behaviour. Agree this with upstream before opening a PR |
| change-password | **Yes** (`PUT`, `new_password`) | Ours was `POST` with `password`. Both routes and both field names now work with one action. Upstream also revokes the user's other tokens |
| forgot/reset password | Upstream only | New |
| `GET /api/stats` | **No**, ours only | |
| Lazy Stripe client | **Yes** | Same idea as ours |
| `course_id` in Stripe URLs | **Yes** | Same URLs as ours: `/payment/success?course_id={id}&session_id={CHECKOUT_SESSION_ID}`, `/payment/cancel?course_id={id}` |
| 404 instead of 200 for missing records | Partly (admin approve/reject only) | The rest of our 404 fixes are ours only |
| Profile updates return `data` | **No**, ours only | |

### Things the React app may depend on

- **Stripe return pages:** `fahhemni-react` reads `?course=` (`PaymentResultPages.jsx`,
  `paymentReturnUrls()`), but the backend now redirects with `?course_id=`. The success and
  cancel pages won't find the course until React also reads `course_id`.
- **my-courses:** upstream returns `{ message, data }` without `courses`. The merged branch returns
  `{ message, courses, data }`. React accepts both. Each item now also contains `sections` with
  lessons and `completed`, because upstream eager-loads them. Items are newest-first
  (`enrolled_at` desc).
- **`is_enrolled` / `completed` are always present now:** `false` for guests, non-students, admin
  and instructor course details. Before, these keys were missing in those cases. React treats
  any non-boolean as unknown, so `false` is now a definite "not enrolled".
- **Lesson uncomplete message:** upstream's text is `Lesson marked as incomplete.` (ours was
  `…not completed.`). The keys are unchanged.
- **change-password:** `PUT` with `new_password` is upstream's contract. `POST` with `password`
  still works. After a change, the user's other tokens are revoked; the current token stays valid.
- **Password reset mail** points to `{APP_URL}/reset-password`. If the reset page lives in React,
  `APP_URL` must be the frontend URL, or the notification should use `FRONTEND_URL`.
- **Admin approve/reject** of a missing course: 404 (was 200), both upstream and ours.
- **Pagination:** the student course list accepts `page` and `per_page` (≤ 50).

### Fixes made during the merge

- `2026_10_24_124020_create_password_reset_tokens_table` failed on every fresh database,
  because the default users migration already creates that table. It now skips if the
  table exists, and its `down()` is a no-op. Worth sending upstream.
- `2026_10_23_201648_create_lesson_progress_table` skips if the table exists, so databases that
  already ran our removed `2026_10_24_100000_create_lesson_progress_table` still migrate.
  Locally, `php artisan migrate:fresh` is the clean option.
- Test assertions that expected `completed` / `is_enrolled` to be **absent** now expect `false`.
  New tests cover `PUT /api/auth/change-password` with `new_password`.

## feature/local-dev-setup

- `UPLOAD_DISK` (default `b2`) and a `local_uploads` disk for local development.
- `SIGNED_URL_MINUTES` for thumbnail/avatar/certificate links (defaults unchanged:
  1000 / 30 / 30 minutes); lesson video links stay at 30 minutes.
- Complete `.env.example`, README section "Local development on Windows".
- API guests get a JSON 401 instead of a 500 when no `Accept` header is sent.

## feature/mvp-endpoints-1

### New endpoints

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/api/student/lessons/{id}/complete` | student | Idempotent; enrolled students only (403 otherwise) |
| DELETE | `/api/student/lessons/{id}/complete` | student | Undo; same response shape with `completed: false` |
| POST | `/api/auth/change-password` | any role | `current_password`, `password`, `password_confirmation`; wrong current password → 422. Upstream's `PUT` with `new_password`/`new_password_confirmation` also works |
| GET | `/api/stats` | public | `students` (not deleted), `courses` (published), `instructors` (active), `lessons` (of published courses) |

### Keys added to existing responses (nothing renamed or removed)

- Course list/details (all course resources): `rating_average` (number or null),
  `ratings_count`. `price` is now always a 2-decimal string (e.g. `"49.99"`).
- `GET /api/student/courses` and `/api/student/courses/{id}` (still public):
  `is_enrolled`, from an optional Sanctum token; it's false for guests, invalid tokens and non-students.
  For an enrolled student every lesson in the details also has `completed`.
- Admin course details: each lesson has `video_status`. Instructor lesson responses
  (`lesson` / lessons list): `video_status`.
- `GET /api/student/my-courses`: keeps `courses`; adds `message` and `data` (same
  array). Each item also has `enrolled_at`, `last_lesson_id`, `progress`.
- `GET /api/courses/{id}/ratings`, `GET /api/instructors/{id}/ratings`: keep
  `course_id`/`instructor_profile_id`, `average_rating`, `ratings_count`; add
  `message` and `data { average, count, ratings[] }`.
- `PUT /api/student/profile`, `PUT /api/instructor/profile`: keep `user`; add `data`
  (same shape as `GET /api/auth/profile`).

### Behaviour changes (worth a look from the frontend)

- Rating a course or instructor a second time now **updates** the rating (200)
  instead of returning 409.
- Some "not found" responses returned HTTP 200 and now return **404** (same body):
  admin approve/reject course, instructor course by id, submit, retry video,
  section lessons, lesson by id, update lesson, update section.
- `PUT /api/student/profile` no longer clears the stored avatar when no new file
  is sent, and validates `avatar` as an image.
- The Stripe client is created only when a payment call is made, so catalog and student
  routes work without `STRIPE_SECRET`.
- Stripe redirects now carry `course_id`:
  `{FRONTEND_URL}/payment/success?course_id={id}&session_id={CHECKOUT_SESSION_ID}` and
  `{FRONTEND_URL}/payment/cancel?course_id={id}`.

### Multipart updates

PHP does not parse `multipart/form-data` bodies on `PUT`. Send file uploads as
`POST` with a `_method=PUT` field, for example `POST /api/student/profile` with
`_method=PUT`, the profile fields and `avatar`. JSON updates without files can use
`PUT` directly.

### Course status flow (current behaviour, unchanged)

| Action | Endpoint | From | To |
|---|---|---|---|
| create | `POST /api/instructor/courses` | n/a | `draft` |
| submit | `POST /api/instructor/courses/{id}/submit` | `draft` only | `pending_publish` |
| approve | `PATCH /api/admin/courses/{id}/approve` | `pending_publish` only | `published` |
| reject | `PATCH /api/admin/courses/{id}/reject` | `pending_publish` only | `rejected` |
| archive | `PATCH /api/instructor/courses/{id}/archive` | any status except `archived` | `archived` |
| restore | `PATCH /api/instructor/courses/{id}/restore` | `archived` only | `draft` (always) |

The statuses are defined in `app/Enums/Course/CourseStatus.php`; the
"pending" status is `pending_publish`.

## Open points

- **Restore** always returns a course to `draft`, not to the status before
  archiving. A published course has to be resubmitted and approved again. Going back to the
  previous status needs a new column (e.g. `status_before_archive`).
- A `rejected` course can't be resubmitted (submit only accepts `draft`), and
  there is no endpoint that moves it back to `draft`.
- `CheckUserStatus` blocks `inactive` users only; the `blocked` check is commented
  out, so a blocked user who still has a token can keep using the API.
- `duration_hours` in course responses is always `null` (no such column; the
  column is `duration_seconds`).
- The typo route `aoi` mentioned in the task does not exist in `routes/`; nothing
  to alias.

## Migrations (in order)

1. `0001_01_01_000000_create_users_table`
2. `0001_01_01_000001_create_cache_table`
3. `0001_01_01_000002_create_jobs_table`
4. `2026_08_26_141235_create_personal_access_tokens_table`
5. `2026_08_26_142014_create_student_profiles_table`
6. `2026_08_26_142342_create_instructor_profiles_table`
7. `2026_08_26_143230_create_permission_tables`
8. `2026_08_26_214805_create_instructor_certificates_table`
9. `2026_09_17_210349_create_categories_table`
10. `2026_09_17_210525_create_courses_table`
11. `2026_09_18_214458_create_sections_table`
12. `2026_09_18_222123_create_lessons_table`
13. `2026_09_18_223317_create_lesson_videos_table`
14. `2026_10_02_171116_create_enrollments_table`
15. `2026_10_02_171243_create_payments_table`
16. `2026_10_19_185405_create_course_reviews_table`
17. `2026_10_19_185406_create_instructor_reviews_table`
18. `2026_10_23_201648_create_lesson_progress_table` (upstream; skips if the table exists)
19. `2026_10_24_124020_create_password_reset_tokens_table` (upstream; skips if the table exists)
