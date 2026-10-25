# Progress

## Branches

| Branch | Based on | Status |
|---|---|---|
| `main` | `upstream/main` (170d889) | Fast-forwarded, pushed to `origin` |
| `feature/local-dev-setup` | `main` | Synced with upstream, pushed to `origin` |
| `feature/mvp-endpoints-1` | `feature/local-dev-setup` | Synced with upstream, pushed to `origin` |
| `feature/mvp-endpoints-2` | `feature/mvp-endpoints-1` | Pagination, reject reasons, contact form, reorder; pushed to `origin` |
| `feature/mvp-endpoints-3` | `feature/mvp-endpoints-2` | Status rules, upload limits, replace video, instructor re-apply, teaching majors, admin payments/users lists, `my_rating`; pushed to `origin` |
| `feature/mvp-endpoints-4` | `feature/mvp-endpoints-3` | Instructor sign-up without an avatar, default reason for a reason-less rejection, `?search=` on admin users; pushed to `origin` |
| `feature/demo-seed-command` | `feature/mvp-endpoints-4` | `php artisan demo:seed` (fake data for end-to-end testing); pushed to `origin` |

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
| Ratings endpoints: list of ratings, `data { average, count, ratings[], my_rating }` | **No**, ours only | |
| Changing a rating | **No**, upstream returns 409 on a second `POST` | **Decided (option C):** `POST` keeps upstream's 409; new `PUT` endpoints update the rating |
| change-password | **Yes** (`PUT`, `new_password`) | Ours was `POST` with `password`. Both routes and both field names now work with one action. Upstream also revokes the user's other tokens |
| forgot/reset password | Upstream only | New |
| `GET /api/stats`, `GET /api/admin/stats` | **No**, ours only | |
| Lazy Stripe client | **Yes** | Same idea as ours |
| `course_id` in Stripe URLs | **Yes** | Same URLs as ours: `/payment/success?course_id={id}&session_id={CHECKOUT_SESSION_ID}`, `/payment/cancel?course_id={id}` |
| 404 instead of 200 for missing records | Partly (admin approve/reject only) | The rest of our 404 fixes and the shared `{Model} not found.` body are ours only |
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
- **Password reset mail** now points to `{FRONTEND_URL}/reset-password?token=…&email=…` (decision 3;
  upstream uses `APP_URL`). React needs a `/reset-password` page that posts `token`, `email`,
  `password`, `password_confirmation` to `POST /api/auth/reset-password`.
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

## Decisions (2026-10-25)

1. **Ratings, option C.** `POST /api/student/courses/{id}/rate` and `/rate-instructor` are exactly
   upstream's: a second `POST` returns **409** `You have already rated this course.` /
   `You have already rated this instructor.` New `PUT` routes on the same paths change an existing
   rating (same fields: `rating` 1-5, `comment` nullable; enrolled students only, 403 otherwise;
   **404** `You have not rated this course yet.` / `…this instructor yet.`; 200 `{ message, data: rating }`).
   `PUT` replaces the rating, so leaving out `comment` clears it.
   `GET /api/courses/{id}/ratings` and `GET /api/instructors/{id}/ratings` add `data.my_rating`: the
   token holder's rating (same item shape as `data.ratings[]`), or `null` for guests, invalid tokens,
   non-students and students who haven't rated. The token is optional (`$request->user('sanctum')`).
2. **Stripe return pages:** to fix in the React app (read `course_id`); the backend is unchanged.
3. **Password reset link** uses `FRONTEND_URL` (`config('app.frontend_url')`), not `APP_URL`.

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
| GET | `/api/stats` | public | `{ message, data: { students, courses, instructors, lessons } }`: students not deleted, published courses, active instructors, lessons of published courses |
| GET | `/api/admin/stats` | admin | `{ message, data: { users_by_role_and_status, courses_by_status, pending_instructor_requests, pending_courses } }`. Every role/status and course status is listed (0 when empty), each group with a `total`; pending instructor requests use the same filter as `GET /api/admin/instructor-requests` |
| PUT | `/api/student/courses/{id}/rate` | student | Change my course rating (decision 1) |
| PUT | `/api/student/courses/{id}/rate-instructor` | student | Change my rating of the course's instructor (decision 1) |

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
  `message` and `data { average, count, ratings[], my_rating }`.
- `PUT /api/student/profile`, `PUT /api/instructor/profile`: keep `user`; add `data`
  (same shape as `GET /api/auth/profile`).

### Behaviour changes (worth a look from the frontend)

- Rating a course or instructor a second time returns **409** (as upstream); use the new `PUT`
  endpoints to change a rating.
- Every missing model looked up by id (`findOrFail`) returns **404** `{ "message": "<Model> not found." }`
  (for example `User not found.`, `Course not found.`) instead of Laravel's
  `No query results for model [App\Models\…]`. `GET /api/admin/users/{id}` for a missing user
  returned a **500** and now returns this 404. Unknown routes keep Laravel's default 404 JSON.
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

### Course status flow (as of mvp-endpoints-1; see mvp-endpoints-3 for the current rules)

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

## feature/mvp-endpoints-2

Everything here is additive: no URL, method or existing key was changed or removed.
Checked against upstream first: upstream only validates `page`/`per_page` on the student
catalog (it doesn't apply `per_page` or return `meta`) and already has forgot/reset password.
None of the items below exist upstream.

### Pagination (`?page=`)

`GET /api/student/courses`, `GET /api/instructor/courses` and `GET /api/admin/courses`:

- **Without `page`:** exactly today's response. Student: `{ message, data }`, 12 per page,
  and `per_page` is ignored. Instructor and admin: `{ courses: [...] }` with every matching course.
- **With `page`:** `{ message, data: [...], meta: { current_page, last_page, total, per_page } }`.
  `per_page` is 1-50, defaulting to 12 (student) or 15 (instructor/admin). Newest first, with `id`
  as tie-breaker. Filters (`status`, `category_id`, `search`) still apply. Invalid values → 422.

### Keys added to course objects

| Key | Where | Value |
|---|---|---|
| `students_count` | Instructor list/details, admin list/details | Number of enrollments |
| `instructor.avatar` / `.teaching_major` / `.bio` | Every course object | Signed avatar URL (or null), enum value, text. Never email or phone |
| `reject_reason` | Instructor's own list (`/instructor/courses`) and details (`/instructor/courses/{id}`) | Reason given by the admin, or null |

### Reject reasons

- `PATCH /api/admin/courses/{id}/reject` and `PATCH /api/admin/instructor-requests/{id}/reject`
  accept an optional `reason` (string, max 500; longer → 422). The responses are unchanged:
  `{ message, status }` for a course, `{ message }` for an instructor.
- Course: stored in `courses.reject_reason`; approving a course clears it.
- Instructor: stored in `instructor_profiles.reject_reason`. Every user object for an instructor
  has `reject_reason` next to `status`: login, `GET /api/auth/profile`, the admin user endpoints,
  and the approve response. It's `null` unless rejected. Students and admins don't get the key. Approving the
  application, or setting the user back to `active` with `PATCH /api/admin/users/{id}/status`,
  clears it. A rejected instructor is `inactive`; login still works, but the
  instructor routes return the existing 403 for inactive users.

### Contact form

| Method | Path | Auth | Response |
|---|---|---|---|
| POST | `/api/contact` | public, 5 requests/minute per IP (429 after) | 201 `{ message }`. `name` ≤ 100, `email` (valid, ≤ 255), `message` ≤ 5000, all required |
| GET | `/api/admin/contact-messages` | admin | `{ message, data, meta }`, newest first; `page`, `per_page` (≤ 50, default 15), `unread=1` |
| PATCH | `/api/admin/contact-messages/{id}/read` | admin | `{ message, data }`; idempotent (keeps the first `read_at`); 404 `Contact message not found.` |

Items: `id`, `name`, `email`, `message`, `is_read`, `read_at`, `created_at`.

### Reorder

| Method | Path | Body |
|---|---|---|
| PATCH | `/api/instructor/courses/{id}/sections/reorder` | `{ ids: [sectionId, ...] }` |
| PATCH | `/api/instructor/sections/{id}/lessons/reorder` | `{ ids: [lessonId, ...] }` |

- `ids` must list **every** section of the course (or lesson of the section) exactly once.
  Missing, extra, foreign, unknown or duplicate ids → 422 (`errors.ids`), and nothing is changed.
- `sort_order` becomes 1..n in the given order, inside a transaction. Response: `{ message }`.
- Owner only (403 otherwise); a missing course or section → 404.

## feature/mvp-endpoints-3

Additive: no URL, method or existing key was changed or removed. The behaviour changes are the
new status rules (422s where the API used to allow the change) and the new upload limits.
No new migrations.

### Course status rules (enforced on the server)

| Action | Allowed when the course is | Otherwise |
|---|---|---|
| submit `POST /instructor/courses/{id}/submit` | `draft` or `rejected` | 422 `Only draft or rejected courses can be submitted for publishing.` |
| update `PUT` / `POST /instructor/courses/{id}` | `draft` or `rejected` | 422 `Only draft or rejected courses can be updated.` |
| delete `DELETE /instructor/courses/{id}` | `draft` | 422 `Only draft courses can be deleted.` |
| section create/update/delete/reorder, lesson create/update/delete/reorder, replace lesson video | parent course `draft` or `rejected` | 422 `Sections and lessons can only be changed while the course is draft or rejected.` |

- Order of checks: 404 (missing) → 403 (not the owner) → 422 (status). Validation errors (422 with
  `errors`) come first, as before. Reads (`GET`) are not restricted.
- Resubmitting a `rejected` course moves it to `pending_publish` and clears `reject_reason`.
  The reason stays stored (and visible to the owner and the admin) until then.
- Archive/restore and retry video are unchanged.

| Action | From | To |
|---|---|---|
| submit | `draft`, `rejected` | `pending_publish` (clears `reject_reason`) |
| approve / reject | `pending_publish` | `published` / `rejected` |
| archive | any except `archived` | `archived` |
| restore | `archived` | `draft` |

### Upload limits

| Field | Types | Max | Setting |
|---|---|---|---|
| course `thumbnail` (create, update) | jpg, jpeg, png, webp | 5 MB | `THUMBNAIL_MAX_KB` (default 5120) |
| lesson `video` (create, replace) | mp4, mov, webm | 2 GB | `VIDEO_MAX_KB` (default 2097152) |

Both are read by `config/uploads.php` and documented in `.env.example`. PHP's
`upload_max_filesize` and `post_max_size` must be at least as large, or PHP drops the upload before
validation runs.

### New endpoints

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/api/instructor/lessons/{id}/video` | instructor (owner) | Multipart, field `video`. Course must be draft/rejected. Stores the new source under `lessons/source`, sets the video to `processing` (playlist `null`, durations 0), removes the old source and HLS files, dispatches `ProcessLessonVideo`. Creates the video row if the lesson had none. 200 `{ message, lesson }` (same `lesson` shape as create) |
| GET | `/api/teaching-majors` | public | `{ data: ["computer_science", ...] }` from `TeachingMajor::values()`, the enum used by both teaching_major validation rules |
| GET | `/api/admin/payments` | admin | Newest first. Without `page`: `{ data: [...] }`. With `page` (`per_page` 1-50, default 15): Laravel's `{ data, links, meta }`. Items: `id`, `student { id, name }` (or null), `course { id, title }` (or null), `amount`, `currency`, `status`, `provider`, `payment_method`, `provider_payment_id`, `stripe_checkout_session_id`, `paid_at`, `created_at`. No client secrets or checkout URLs (they are not stored) |

### Changes to existing endpoints

- `GET /api/admin/users`: optional `page` and `per_page` (1-50, default 15). Without `page` the
  response is exactly as before (`{ data: [...] }`, every user); with it, `{ data, links, meta }`,
  newest first, filters kept in the links. `role` and `status` filters as before.
- `GET /api/admin/courses/{id}`: adds `reject_reason` (null unless rejected). The admin list doesn't have it.
- `GET /api/student/my-courses`: each course adds `my_rating`, the student's own course rating
  (1-5) or `null`.
- `POST /api/auth/register-instructor`:
  - **Re-apply:** an email that belongs to a rejected application (instructor, `inactive`, with a
    `reject_reason`) re-submits that application instead of failing with a 500. The user and
    profile get the new data and password, `status` goes back to `pending`, `reject_reason` is
    cleared, and the user's tokens are revoked. A new `avatar` or `certifications` replace the
    old files; without them the old ones are kept. Response: the normal 201
    `{ message, instructor }`.
  - Any other existing email: 422 `errors.email` `The email has already been taken.` (was a 500).
  - A phone that belongs to another user: 422 `errors.phone` (was a 500).

## feature/mvp-endpoints-4

Additive: no URL, method or existing key was changed or removed. One new migration (22).

### Instructor sign-up without an avatar

- `instructor_profiles.avatar` is now nullable (migration 22, Laravel's native `->nullable()->change()`,
  no doctrine/dbal; works on SQLite, MySQL and PostgreSQL). `POST /api/auth/register-instructor`
  without `avatar` → 201 with `instructor.profile.avatar: null` (it was a 500).
- Every response that shows the instructor's avatar already returns `null` for a missing one
  (`null` or `''`): course objects (`instructor.avatar`), login / profile, admin users,
  instructor requests and admin course details. Tests cover each.
- `down()` turns `NULL` avatars into `''` before making the column NOT NULL again.

### Rejecting an instructor without a reason

- `PATCH /api/admin/instructor-requests/{id}/reject` without a `reason` (missing, `null`, empty or
  blank) now stores `"Rejected by admin"` (`InstructorProfile::DEFAULT_REJECT_REASON`). A given
  reason is stored as before. The response is unchanged.
- So every rejected application has a `reject_reason` and can re-apply with the same email,
  exactly like one rejected with a reason (201, back to `pending`, reason cleared).
- An account the admin only set to `inactive` (`PATCH /api/admin/users/{id}/status`) has no
  `reject_reason`: signing up with its email is still 422 `The email has already been taken.`
- Course rejections are unchanged (`reject_reason` stays `null` without a reason).
- Instructors rejected without a reason **before** this change still have `reject_reason = null`
  and can't re-apply. To let them, an admin can set the reason once (a one-off data fix such
  as `UPDATE instructor_profiles SET reject_reason = 'Rejected by admin' WHERE …` for the known
  rejected applicants); the backend can't tell them apart from deactivated accounts.

### `GET /api/admin/users?search=`

- Optional, string, ≤ 100 characters (422 otherwise). Empty or blank → ignored.
- Every word must match the email or a name part (first, father or last name of the student or
  instructor profile), case-insensitively (`LOWER(…) LIKE`, so PostgreSQL too). `%` and `_` in
  the input are matched literally.
- Combines with `role`, `status`, `page` and `per_page`; the pagination links keep `search`.
  Without `search` the response is exactly as before.

## feature/demo-seed-command

Additive: one artisan command (`app/Console/Commands/DemoSeed.php`) and `config/demo.php`. No migration, no change to the API.

`php artisan demo:seed` fills an empty or existing database with fake data:

| What | Rows |
|---|---|
| Accounts (all `@example.test`) | `admin@example.test`; `instructor1`–`instructor3@example.test` (active); `instructor4@example.test` (pending approval); `instructor5@example.test` (rejected: `inactive` with a `reject_reason`); `student1`–`student3@example.test` |
| Categories | Programming, Civil Engineering, Business, Design |
| Courses (7) | Laravel Basics (published, free), Advanced Eloquent (published, 49.99), Concrete Design 101 (published, 29.00), Startup Finance (pending_publish), UI Sketching (draft), Bridge Loads (rejected, with a reason), Old Surveying (archived); 2 sections × 2 lessons each, every lesson video `ready` |
| Learning | 5 enrollments, lesson progress for two students, 3 course ratings and 1 instructor rating with comments |
| Admin | 1 contact message; 2 payments (one `paid`, one `pending` on the archived course so it never blocks buying a published one) |

- **Passwords:** never hardcoded. Every demo account gets `DEMO_SEED_PASSWORD` when set (and it is not printed); otherwise a random 16-character password is generated on each run and printed once.
- **Production:** refuses to run when `APP_ENV=production` unless `DEMO_SEED_ALLOW=true`.
- **Idempotent:** every row is found by a stable key (email, category name, course title + demo instructor, section / lesson title in their parent, the unique pairs for enrollments, progress, ratings and payments) and updated; a second run adds nothing. A real course with the same title as a demo one is not touched.
- **No external calls, no storage:** no Stripe, no file is written. Lessons have placeholder video paths (listed as ready, not playable), thumbnails are empty (the API returns `null`, the frontend shows its default image), avatars are `null`. The payments have no Stripe ids, so `payments:expire-unpaid` only changes the pending one locally.
- **Tests:** `DemoSeedCommandTest` (refuses in production, runs with the flag, every role and status, idempotent, leaves real data alone, never prints a configured password, prints a generated one once and it works, no Stripe or storage call, the seeded accounts log in and the catalog shows the published courses).

### Run the local backend for end-to-end testing (Windows, PowerShell)

```powershell
php artisan migrate --force
$env:DEMO_SEED_PASSWORD = 'choose-a-local-password'   # optional; without it a random one is printed once
php artisan demo:seed
php artisan serve
```

In a second terminal: `php artisan queue:work`. It is only needed when `QUEUE_CONNECTION=database`, for processing uploaded videos, which also needs ffmpeg (`FFMPEG_PATH`, `FFPROBE_PATH`). The seeded lessons don't need it.

The frontend's `fahhemni-react/.env.local` should contain `VITE_API_BASE_URL=http://127.0.0.1:8000/api`.

## Open points

- **Restore** always returns a course to `draft`, not to the status before
  archiving. A published course has to be resubmitted and approved again. Going back to the
  previous status needs a new column (e.g. `status_before_archive`).
- ~~An instructor rejected **without** a reason can't re-apply~~: fixed in mvp-endpoints-4 (default
  reason). Applicants rejected without a reason before that still need a one-off data fix (see above).
- ~~`instructor_profiles.avatar` is NOT NULL~~: nullable since mvp-endpoints-4 (migration 22).
- The original seeders (`AdminSeeder`, `InstructorSeeder`, `StudentSeeder`, run by `php artisan db:seed`)
  set hardcoded passwords. They are upstream code and were left as they are; `demo:seed` doesn't use them.
- `CheckUserStatus` blocks `inactive` users only; the `blocked` check is commented
  out, so a blocked user who still has a token can keep using the API.
- `duration_hours` in course responses is always `null` (no such column; the
  column is `duration_seconds`).
- The typo route `aoi` mentioned in the task does not exist in `routes/`; nothing
  to alias.

## Migrations (in order)

The ratings `PUT` endpoints, `my_rating`, admin stats and 404 handling need no new
migration. `feature/mvp-endpoints-2` adds 20 and 21; `feature/mvp-endpoints-3` adds none;
`feature/mvp-endpoints-4` adds 22. All are additive: two nullable columns, one new table, and one
column made nullable. They have only been run in the test database, not
against any shared or production database. The list below matches `database/migrations/`.

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
20. `2026_10_26_100000_add_reject_reason_to_courses_and_instructor_profiles` (mvp-endpoints-2;
    nullable `reject_reason` varchar(500) on `courses` and `instructor_profiles`)
21. `2026_10_26_100100_create_contact_messages_table` (mvp-endpoints-2; `name`, `email`,
    `message`, nullable `read_at`, timestamps)
22. `2026_10_27_100000_make_instructor_profiles_avatar_nullable` (mvp-endpoints-4;
    `instructor_profiles.avatar` nullable; `down()` stores `''` for missing avatars first)
