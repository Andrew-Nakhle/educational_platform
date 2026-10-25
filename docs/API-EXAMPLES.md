# API examples

Real responses captured by `tests/Feature/ApiExamplesTest.php` running the
application with fake data (`*@example.test` users, a faked upload disk,
a faked Stripe HTTP client and a faked queue). Tokens and client secrets
are replaced with placeholders; signed file URLs are fake.

Regenerate (PowerShell):

```powershell
$env:API_EXAMPLES=1; php artisan test --filter=ApiExamplesTest; Remove-Item Env:API_EXAMPLES
```

All requests send `Accept: application/json`; authenticated requests send
`Authorization: Bearer <token>` with the token returned by `POST /api/auth/login`.
Multipart requests (file uploads) must be sent as `POST`; to update with a file
use `POST` with a `_method=PUT` field.

## Contents

1. [Login (admin)](#example-1-login) `POST /api/auth/login`
2. [Login (instructor)](#example-2-login) `POST /api/auth/login`
3. [Login (student)](#example-3-login) `POST /api/auth/login`
4. [Current user profile (student)](#example-4-current-user-profile) `GET /api/auth/profile`
5. [Create a course (multipart)](#example-5-create-a-course) `POST /api/instructor/courses`
6. [List my courses (instructor)](#example-6-list-my-courses) `GET /api/instructor/courses`
7. [Course by id (instructor)](#example-7-course-by-id) `GET /api/instructor/courses/5`
8. [Update a course (JSON; send multipart as POST with _method=PUT to change the thumbnail)](#example-8-update-a-course) `PUT /api/instructor/courses/5`
9. [Create a section](#example-9-create-a-section) `POST /api/instructor/courses/5/sections`
10. [Create a lesson with a video (multipart; processing is queued)](#example-10-create-a-lesson-with-a-video) `POST /api/instructor/sections/2/lessons`
11. [Submit a course for review](#example-11-submit-a-course-for-review) `POST /api/instructor/courses/5/submit`
12. [List users (admin)](#example-12-list-users) `GET /api/admin/users`
13. [User by id (admin)](#example-13-user-by-id) `GET /api/admin/users/3`
14. [Change a user status (admin)](#example-14-change-a-user-status) `PATCH /api/admin/users/4/status`
15. [List courses (admin)](#example-15-list-courses) `GET /api/admin/courses`
16. [Course by id (admin)](#example-16-course-by-id) `GET /api/admin/courses/1`
17. [Approve a course (admin)](#example-17-approve-a-course) `PATCH /api/admin/courses/5/approve`
18. [Reject a course with an optional reason (admin)](#example-18-reject-a-course-with-an-optional-reason) `PATCH /api/admin/courses/4/reject`
19. [Rejected course with reject_reason (instructor, own course)](#example-19-rejected-course-with-reject-reason) `GET /api/instructor/courses/4`
20. [Course details (guest, no token)](#example-20-course-details) `GET /api/student/courses/1`
21. [Enroll in a FREE course](#example-21-enroll-in-a-free-course) `POST /api/student/courses/1/enroll`
22. [Enroll in a PAID course (web, Stripe Checkout; Stripe is faked)](#example-22-enroll-in-a-paid-course) `POST /api/student/courses/2/enroll`
23. [Mark a lesson as completed](#example-23-mark-a-lesson-as-completed) `POST /api/student/lessons/1/complete`
24. [Course details (enrolled student)](#example-24-course-details) `GET /api/student/courses/1`
25. [My courses](#example-25-my-courses) `GET /api/student/my-courses`
26. [Rate a course](#example-26-rate-a-course) `POST /api/student/courses/1/rate`
27. [Rate a course a second time (409, use PUT to change it)](#example-27-rate-a-course-a-second-time) `POST /api/student/courses/1/rate`
28. [Update my course rating](#example-28-update-my-course-rating) `PUT /api/student/courses/1/rate`
29. [Update a rating that does not exist yet (404)](#example-29-update-a-rating-that-does-not-exist-yet) `PUT /api/student/courses/1/rate-instructor`
30. [Rate the instructor of a course](#example-30-rate-the-instructor-of-a-course) `POST /api/student/courses/1/rate-instructor`
31. [Update my instructor rating](#example-31-update-my-instructor-rating) `PUT /api/student/courses/1/rate-instructor`
32. [Course ratings (guest, my_rating is null)](#example-32-course-ratings) `GET /api/courses/1/ratings`
33. [Course ratings (student token, with my_rating)](#example-33-course-ratings) `GET /api/courses/1/ratings`
34. [Instructor ratings (student token, with my_rating)](#example-34-instructor-ratings) `GET /api/instructors/1/ratings`
35. [Update the student profile (JSON)](#example-35-update-the-student-profile) `PUT /api/student/profile`
36. [Update the instructor profile (multipart POST with _method=PUT)](#example-36-update-the-instructor-profile) `POST /api/instructor/profile`
37. [Forgot password (sends the reset mail)](#example-37-forgot-password) `POST /api/auth/forgot-password`
38. [Platform statistics (public)](#example-38-platform-statistics) `GET /api/stats`
39. [Admin statistics](#example-39-admin-statistics) `GET /api/admin/stats`
40. [Missing record (404 for any missing model)](#example-40-missing-record) `GET /api/admin/users/999`
41. [Course catalog, paginated](#example-41-course-catalog-paginated) `GET /api/student/courses`
42. [My courses (instructor), paginated](#example-42-my-courses) `GET /api/instructor/courses`
43. [List courses (admin), paginated](#example-43-list-courses) `GET /api/admin/courses`
44. [Reject an instructor application with an optional reason (admin)](#example-44-reject-an-instructor-application-with-an-optional-reason) `PATCH /api/admin/instructor-requests/5/reject`
45. [Login (rejected instructor, with reject_reason)](#example-45-login) `POST /api/auth/login`
46. [Send a contact message (public, 5 per minute)](#example-46-send-a-contact-message) `POST /api/contact`
47. [Contact messages (admin)](#example-47-contact-messages) `GET /api/admin/contact-messages`
48. [Mark a contact message as read (admin)](#example-48-mark-a-contact-message-as-read) `PATCH /api/admin/contact-messages/1/read`
49. [Reorder the sections of a course (instructor)](#example-49-reorder-the-sections-of-a-course) `PATCH /api/instructor/courses/6/sections/reorder`
50. [Reorder the lessons of a section (instructor)](#example-50-reorder-the-lessons-of-a-section) `PATCH /api/instructor/sections/3/lessons/reorder`
51. [Reorder with ids that are not exactly the section lessons (422)](#example-51-reorder-with-ids-that-are-not-exactly-the-section-lessons) `PATCH /api/instructor/sections/3/lessons/reorder`
52. [Add a section to a published course (422)](#example-52-add-a-section-to-a-published-course) `POST /api/instructor/courses/1/sections`
53. [Update a published course (422)](#example-53-update-a-published-course) `PUT /api/instructor/courses/1`
54. [Delete a course that is not a draft (422)](#example-54-delete-a-course-that-is-not-a-draft) `DELETE /api/instructor/courses/1`
55. [Replace the video of a lesson (multipart; draft or rejected course; processing is queued)](#example-55-replace-the-video-of-a-lesson) `POST /api/instructor/lessons/4/video`
56. [Resubmit a rejected course (clears reject_reason)](#example-56-resubmit-a-rejected-course) `POST /api/instructor/courses/4/submit`
57. [Re-apply as a rejected instructor (same email, multipart; back to pending)](#example-57-re-apply-as-a-rejected-instructor) `POST /api/auth/register-instructor`
58. [Register as instructor with an email that is already used (422)](#example-58-register-as-instructor-with-an-email-that-is-already-used) `POST /api/auth/register-instructor`
59. [Teaching majors (public)](#example-59-teaching-majors) `GET /api/teaching-majors`
60. [List users (admin), filtered and paginated](#example-60-list-users) `GET /api/admin/users`
61. [List payments (admin)](#example-61-list-payments) `GET /api/admin/payments`
62. [List payments (admin), paginated](#example-62-list-payments) `GET /api/admin/payments`
63. [Course by id with reject_reason (admin)](#example-63-course-by-id-with-reject-reason) `GET /api/admin/courses/3`
64. [My courses, with my_rating](#example-64-my-courses-with-my-rating) `GET /api/student/my-courses`

<a id="example-1-login"></a>
## 1. Login (admin)

`POST /api/auth/login`

- Auth: none
- Body (JSON):

```json
{
    "email": "admin@example.test",
    "password": "secret-password"
}
```

Response `200`:

```json
{
    "message": "Login successful.",
    "user": {
        "id": 1,
        "phone": "0900000001",
        "email": "admin@example.test",
        "gender": "male",
        "age": 31,
        "role": "admin",
        "status": "active",
        "profile": {
            "type": "admin"
        }
    },
    "token": "<token>"
}
```

<a id="example-2-login"></a>
## 2. Login (instructor)

`POST /api/auth/login`

- Auth: none
- Body (JSON):

```json
{
    "email": "instructor@example.test",
    "password": "secret-password"
}
```

Response `200`:

```json
{
    "message": "Login successful.",
    "user": {
        "id": 2,
        "phone": "0900000002",
        "email": "instructor@example.test",
        "gender": "male",
        "age": 31,
        "role": "instructor",
        "status": "active",
        "reject_reason": null,
        "profile": {
            "type": "instructor",
            "id": 1,
            "first_name": "Khaled",
            "father_name": "Samir",
            "last_name": "Nasser",
            "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
            "teaching_major": "computer_science",
            "bio": "Backend developer and teacher.",
            "certifications": []
        }
    },
    "token": "<token>"
}
```

<a id="example-3-login"></a>
## 3. Login (student)

`POST /api/auth/login`

- Auth: none
- Body (JSON):

```json
{
    "email": "student@example.test",
    "password": "secret-password"
}
```

Response `200`:

```json
{
    "message": "Login successful.",
    "user": {
        "id": 3,
        "phone": "0900000003",
        "email": "student@example.test",
        "gender": "male",
        "age": 31,
        "role": "student",
        "status": "active",
        "profile": {
            "type": "student",
            "id": 1,
            "first_name": "Lina",
            "father_name": "Fadi",
            "last_name": "Haddad",
            "avatar": null
        }
    },
    "token": "<token>"
}
```

<a id="example-4-current-user-profile"></a>
## 4. Current user profile (student)

`GET /api/auth/profile`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "id": 3,
    "phone": "0900000003",
    "email": "student@example.test",
    "gender": "male",
    "age": 31,
    "role": "student",
    "status": "active",
    "profile": {
        "type": "student",
        "id": 1,
        "first_name": "Lina",
        "father_name": "Fadi",
        "last_name": "Haddad",
        "avatar": null
    }
}
```

<a id="example-5-create-a-course"></a>
## 5. Create a course (multipart)

`POST /api/instructor/courses`

- Auth: `Authorization: Bearer <token>`
- Body (multipart/form-data):

```json
{
    "title": "Testing Laravel APIs",
    "description": "Feature tests for REST APIs.",
    "price": "29.00",
    "category_id": 1,
    "language": "English",
    "difficulty_level": "medium",
    "what_you_will_learn": "HTTP tests, factories and fakes.",
    "thumbnail": "<file: thumbnail.png>"
}
```

Response `201`:

```json
{
    "message": "Course created successfully.",
    "course": {
        "id": 5,
        "title": "Testing Laravel APIs",
        "description": "Feature tests for REST APIs.",
        "price": "29.00",
        "thumbnail": "https://api.example.test/courses/thumbnails/example12xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792896000",
        "language": "English",
        "difficulty_level": "medium",
        "what_you_will_learn": "HTTP tests, factories and fakes.",
        "status": "draft",
        "duration_hours": null,
        "is_enrolled": false,
        "category": {
            "id": 1,
            "name": "Programming"
        },
        "instructor": {
            "id": 1,
            "first_name": "Khaled",
            "father_name": "Samir",
            "last_name": "Nasser",
            "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
            "teaching_major": "computer_science",
            "bio": "Backend developer and teacher."
        },
        "rating_average": null,
        "ratings_count": 0
    }
}
```

<a id="example-6-list-my-courses"></a>
## 6. List my courses (instructor)

`GET /api/instructor/courses`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "courses": [
        {
            "id": 1,
            "title": "Laravel Basics",
            "description": "Build REST APIs with Laravel.",
            "price": "0.00",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "easy",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "published",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Khaled",
                "father_name": "Samir",
                "last_name": "Nasser",
                "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
                "teaching_major": "computer_science",
                "bio": "Backend developer and teacher."
            },
            "students_count": 0,
            "reject_reason": null,
            "rating_average": null,
            "ratings_count": 0
        },
        {
            "id": 2,
            "title": "Advanced Eloquent",
            "description": "Build REST APIs with Laravel.",
            "price": "49.99",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "hard",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "published",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Khaled",
                "father_name": "Samir",
                "last_name": "Nasser",
                "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
                "teaching_major": "computer_science",
                "bio": "Backend developer and teacher."
            },
            "students_count": 0,
            "reject_reason": null,
            "rating_average": null,
            "ratings_count": 0
        },
        {
            "id": 3,
            "title": "Vue Crash Course",
            "description": "Build REST APIs with Laravel.",
            "price": "0.00",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "easy",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "rejected",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Khaled",
                "father_name": "Samir",
                "last_name": "Nasser",
                "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
                "teaching_major": "computer_science",
                "bio": "Backend developer and teacher."
            },
            "students_count": 0,
            "reject_reason": "Please add a short introduction lesson.",
            "rating_average": null,
            "ratings_count": 0
        },
        {
            "id": 4,
            "title": "Draft Ideas",
            "description": "Build REST APIs with Laravel.",
            "price": "0.00",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "easy",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "pending_publish",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Khaled",
                "father_name": "Samir",
                "last_name": "Nasser",
                "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
                "teaching_major": "computer_science",
                "bio": "Backend developer and teacher."
            },
            "students_count": 0,
            "reject_reason": null,
            "rating_average": null,
            "ratings_count": 0
        },
        {
            "id": 5,
            "title": "Testing Laravel APIs",
            "description": "Feature tests for REST APIs.",
            "price": "29.00",
            "thumbnail": "https://api.example.test/courses/thumbnails/example12xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "medium",
            "what_you_will_learn": "HTTP tests, factories and fakes.",
            "status": "draft",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Khaled",
                "father_name": "Samir",
                "last_name": "Nasser",
                "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
                "teaching_major": "computer_science",
                "bio": "Backend developer and teacher."
            },
            "students_count": 0,
            "reject_reason": null,
            "rating_average": null,
            "ratings_count": 0
        }
    ]
}
```

<a id="example-7-course-by-id"></a>
## 7. Course by id (instructor)

`GET /api/instructor/courses/5`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "course": {
        "id": 5,
        "title": "Testing Laravel APIs",
        "description": "Feature tests for REST APIs.",
        "price": "29.00",
        "thumbnail": "https://api.example.test/courses/thumbnails/example12xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792896000",
        "language": "English",
        "difficulty_level": "medium",
        "what_you_will_learn": "HTTP tests, factories and fakes.",
        "status": "draft",
        "duration_hours": null,
        "is_enrolled": false,
        "category": {
            "id": 1,
            "name": "Programming"
        },
        "instructor": {
            "id": 1,
            "first_name": "Khaled",
            "father_name": "Samir",
            "last_name": "Nasser",
            "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
            "teaching_major": "computer_science",
            "bio": "Backend developer and teacher."
        },
        "students_count": 0,
        "reject_reason": null,
        "rating_average": null,
        "ratings_count": 0
    }
}
```

<a id="example-8-update-a-course"></a>
## 8. Update a course (JSON; send multipart as POST with _method=PUT to change the thumbnail)

`PUT /api/instructor/courses/5`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "title": "Testing Laravel APIs (2nd edition)",
    "price": "35.5"
}
```

Response `200`:

```json
{
    "message": "Course updated successfully.",
    "course": {
        "id": 5,
        "title": "Testing Laravel APIs (2nd edition)",
        "description": "Feature tests for REST APIs.",
        "price": "35.50",
        "thumbnail": "https://api.example.test/courses/thumbnails/example12xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792896000",
        "language": "English",
        "difficulty_level": "medium",
        "what_you_will_learn": "HTTP tests, factories and fakes.",
        "status": "draft",
        "duration_hours": null,
        "is_enrolled": false,
        "category": {
            "id": 1,
            "name": "Programming"
        },
        "instructor": {
            "id": 1,
            "first_name": "Khaled",
            "father_name": "Samir",
            "last_name": "Nasser",
            "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
            "teaching_major": "computer_science",
            "bio": "Backend developer and teacher."
        },
        "rating_average": null,
        "ratings_count": 0
    }
}
```

<a id="example-9-create-a-section"></a>
## 9. Create a section

`POST /api/instructor/courses/5/sections`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "title": "HTTP tests"
}
```

Response `201`:

```json
{
    "message": "Section created successfully.",
    "section": {
        "title": "HTTP tests",
        "sort_order": 1,
        "course_id": 5,
        "updated_at": "2026-10-24T10:00:00.000000Z",
        "created_at": "2026-10-24T10:00:00.000000Z",
        "id": 2
    }
}
```

<a id="example-10-create-a-lesson-with-a-video"></a>
## 10. Create a lesson with a video (multipart; processing is queued)

`POST /api/instructor/sections/2/lessons`

- Auth: `Authorization: Bearer <token>`
- Body (multipart/form-data):

```json
{
    "title": "Your first feature test",
    "description": "Write and run a feature test.",
    "video": "<file: lesson.mp4>"
}
```

Response `201`:

```json
{
    "message": "Lesson created successfully. Video processing started.",
    "lesson": {
        "id": 3,
        "section_id": 2,
        "title": "Your first feature test",
        "description": "Write and run a feature test.",
        "duration_seconds": 0,
        "sort_order": 1,
        "video_status": "processing",
        "video": {
            "id": 3,
            "status": "processing",
            "playlist_path": null,
            "duration_seconds": 0
        }
    }
}
```

<a id="example-11-submit-a-course-for-review"></a>
## 11. Submit a course for review

`POST /api/instructor/courses/5/submit`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Course submitted for publishing successfully.",
    "status": "pending_publish"
}
```

<a id="example-12-list-users"></a>
## 12. List users (admin)

`GET /api/admin/users`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "data": [
        {
            "id": 1,
            "phone": "0900000001",
            "email": "admin@example.test",
            "gender": "male",
            "age": 31,
            "role": "admin",
            "status": "active",
            "profile": {
                "type": "admin"
            }
        },
        {
            "id": 2,
            "phone": "0900000002",
            "email": "instructor@example.test",
            "gender": "male",
            "age": 31,
            "role": "instructor",
            "status": "active",
            "reject_reason": null,
            "profile": {
                "type": "instructor",
                "id": 1,
                "first_name": "Khaled",
                "father_name": "Samir",
                "last_name": "Nasser",
                "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
                "teaching_major": "computer_science",
                "bio": "Backend developer and teacher.",
                "certifications": []
            }
        },
        {
            "id": 3,
            "phone": "0900000003",
            "email": "student@example.test",
            "gender": "male",
            "age": 31,
            "role": "student",
            "status": "active",
            "profile": {
                "type": "student",
                "id": 1,
                "first_name": "Lina",
                "father_name": "Fadi",
                "last_name": "Haddad",
                "avatar": null
            }
        },
        {
            "id": 4,
            "phone": "0900000004",
            "email": "student2@example.test",
            "gender": "male",
            "age": 31,
            "role": "student",
            "status": "active",
            "profile": {
                "type": "student",
                "id": 2,
                "first_name": "Rami",
                "father_name": "Fadi",
                "last_name": "Saleh",
                "avatar": null
            }
        }
    ]
}
```

<a id="example-13-user-by-id"></a>
## 13. User by id (admin)

`GET /api/admin/users/3`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "id": 3,
    "phone": "0900000003",
    "email": "student@example.test",
    "gender": "male",
    "age": 31,
    "role": "student",
    "status": "active",
    "profile": {
        "type": "student",
        "id": 1,
        "first_name": "Lina",
        "father_name": "Fadi",
        "last_name": "Haddad",
        "avatar": null
    }
}
```

<a id="example-14-change-a-user-status"></a>
## 14. Change a user status (admin)

`PATCH /api/admin/users/4/status`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "status": "inactive"
}
```

Response `200`:

```json
{
    "id": 4,
    "phone": "0900000004",
    "email": "student2@example.test",
    "gender": "male",
    "age": 31,
    "role": "student",
    "status": "inactive",
    "profile": {
        "type": "student",
        "id": 2,
        "first_name": "Rami",
        "father_name": "Fadi",
        "last_name": "Saleh",
        "avatar": null
    }
}
```

<a id="example-15-list-courses"></a>
## 15. List courses (admin)

`GET /api/admin/courses?status=pending_publish`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "courses": [
        {
            "id": 4,
            "title": "Draft Ideas",
            "description": "Build REST APIs with Laravel.",
            "price": "0.00",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "easy",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "pending_publish",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Khaled",
                "father_name": "Samir",
                "last_name": "Nasser",
                "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
                "teaching_major": "computer_science",
                "bio": "Backend developer and teacher."
            },
            "students_count": 0,
            "rating_average": null,
            "ratings_count": 0
        },
        {
            "id": 5,
            "title": "Testing Laravel APIs (2nd edition)",
            "description": "Feature tests for REST APIs.",
            "price": "35.50",
            "thumbnail": "https://api.example.test/courses/thumbnails/example12xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "medium",
            "what_you_will_learn": "HTTP tests, factories and fakes.",
            "status": "pending_publish",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Khaled",
                "father_name": "Samir",
                "last_name": "Nasser",
                "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
                "teaching_major": "computer_science",
                "bio": "Backend developer and teacher."
            },
            "students_count": 0,
            "rating_average": null,
            "ratings_count": 0
        }
    ]
}
```

<a id="example-16-course-by-id"></a>
## 16. Course by id (admin)

`GET /api/admin/courses/1`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "course": {
        "id": 1,
        "title": "Laravel Basics",
        "description": "Build REST APIs with Laravel.",
        "price": "0.00",
        "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
        "language": "English",
        "difficulty_level": "easy",
        "what_you_will_learn": "Routing, Eloquent and testing.",
        "status": "published",
        "duration_hours": null,
        "is_enrolled": false,
        "category": {
            "id": 1,
            "name": "Programming"
        },
        "instructor": {
            "id": 1,
            "first_name": "Khaled",
            "father_name": "Samir",
            "last_name": "Nasser",
            "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
            "teaching_major": "computer_science",
            "bio": "Backend developer and teacher."
        },
        "students_count": 0,
        "reject_reason": null,
        "rating_average": null,
        "ratings_count": 0,
        "sections": [
            {
                "id": 1,
                "title": "Getting started",
                "description": null,
                "sort_order": 1,
                "lessons": [
                    {
                        "id": 1,
                        "title": "Installing Laravel",
                        "description": "Lesson description.",
                        "duration_seconds": 420,
                        "sort_order": 1,
                        "completed": false,
                        "video_status": "ready"
                    },
                    {
                        "id": 2,
                        "title": "Routing",
                        "description": "Lesson description.",
                        "duration_seconds": 610,
                        "sort_order": 2,
                        "completed": false,
                        "video_status": "ready"
                    }
                ]
            }
        ]
    }
}
```

<a id="example-17-approve-a-course"></a>
## 17. Approve a course (admin)

`PATCH /api/admin/courses/5/approve`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Course approved and published successfully.",
    "status": "published"
}
```

<a id="example-18-reject-a-course-with-an-optional-reason"></a>
## 18. Reject a course with an optional reason (admin)

`PATCH /api/admin/courses/4/reject`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "reason": "The audio in section 2 is too quiet."
}
```

Response `200`:

```json
{
    "message": "Course rejected successfully.",
    "status": "rejected"
}
```

<a id="example-19-rejected-course-with-reject-reason"></a>
## 19. Rejected course with reject_reason (instructor, own course)

`GET /api/instructor/courses/4`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "course": {
        "id": 4,
        "title": "Draft Ideas",
        "description": "Build REST APIs with Laravel.",
        "price": "0.00",
        "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
        "language": "English",
        "difficulty_level": "easy",
        "what_you_will_learn": "Routing, Eloquent and testing.",
        "status": "rejected",
        "duration_hours": null,
        "is_enrolled": false,
        "category": {
            "id": 1,
            "name": "Programming"
        },
        "instructor": {
            "id": 1,
            "first_name": "Khaled",
            "father_name": "Samir",
            "last_name": "Nasser",
            "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
            "teaching_major": "computer_science",
            "bio": "Backend developer and teacher."
        },
        "students_count": 0,
        "reject_reason": "The audio in section 2 is too quiet.",
        "rating_average": null,
        "ratings_count": 0
    }
}
```

<a id="example-20-course-details"></a>
## 20. Course details (guest, no token)

`GET /api/student/courses/1`

- Auth: none

Response `200`:

```json
{
    "message": "Course details retrieved successfully.",
    "data": {
        "id": 1,
        "title": "Laravel Basics",
        "description": "Build REST APIs with Laravel.",
        "price": "0.00",
        "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
        "language": "English",
        "difficulty_level": "easy",
        "what_you_will_learn": "Routing, Eloquent and testing.",
        "status": "published",
        "duration_hours": null,
        "is_enrolled": false,
        "category": {
            "id": 1,
            "name": "Programming"
        },
        "instructor": {
            "id": 1,
            "first_name": "Khaled",
            "father_name": "Samir",
            "last_name": "Nasser",
            "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
            "teaching_major": "computer_science",
            "bio": "Backend developer and teacher."
        },
        "rating_average": null,
        "ratings_count": 0,
        "sections": [
            {
                "id": 1,
                "title": "Getting started",
                "description": null,
                "sort_order": 1,
                "lessons": [
                    {
                        "id": 1,
                        "title": "Installing Laravel",
                        "description": "Lesson description.",
                        "duration_seconds": 420,
                        "sort_order": 1,
                        "completed": false
                    },
                    {
                        "id": 2,
                        "title": "Routing",
                        "description": "Lesson description.",
                        "duration_seconds": 610,
                        "sort_order": 2,
                        "completed": false
                    }
                ]
            }
        ]
    }
}
```

<a id="example-21-enroll-in-a-free-course"></a>
## 21. Enroll in a FREE course

`POST /api/student/courses/1/enroll`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "payment_flow": "web"
}
```

Response `201`:

```json
{
    "message": "You have enrolled in the course successfully.",
    "enrollment": {
        "course_id": 1,
        "enrolled_at": "2026-10-24T10:00:00.000000Z",
        "user_id": 3,
        "updated_at": "2026-10-24T10:00:00.000000Z",
        "created_at": "2026-10-24T10:00:00.000000Z",
        "id": 1
    }
}
```

<a id="example-22-enroll-in-a-paid-course"></a>
## 22. Enroll in a PAID course (web, Stripe Checkout; Stripe is faked)

`POST /api/student/courses/2/enroll`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "payment_flow": "web"
}
```

Response `201`:

```json
{
    "message": "Continue to payment.",
    "payment_id": 1,
    "checkout_url": "https://checkout.stripe.test/c/pay/cs_test_example"
}
```

Redirect URLs sent to Stripe:
- success_url: `https://frontend.example.test/payment/success?course_id=2&session_id={CHECKOUT_SESSION_ID}`
- cancel_url: `https://frontend.example.test/payment/cancel?course_id=2`

<a id="example-23-mark-a-lesson-as-completed"></a>
## 23. Mark a lesson as completed

`POST /api/student/lessons/1/complete`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Lesson marked as completed.",
    "data": {
        "lesson_id": 1,
        "completed": true,
        "progress": {
            "course_id": 1,
            "completed_lessons": 1,
            "total_lessons": 2,
            "percent": 50
        }
    }
}
```

<a id="example-24-course-details"></a>
## 24. Course details (enrolled student)

`GET /api/student/courses/1`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Course details retrieved successfully.",
    "data": {
        "id": 1,
        "title": "Laravel Basics",
        "description": "Build REST APIs with Laravel.",
        "price": "0.00",
        "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
        "language": "English",
        "difficulty_level": "easy",
        "what_you_will_learn": "Routing, Eloquent and testing.",
        "status": "published",
        "duration_hours": null,
        "is_enrolled": true,
        "category": {
            "id": 1,
            "name": "Programming"
        },
        "instructor": {
            "id": 1,
            "first_name": "Khaled",
            "father_name": "Samir",
            "last_name": "Nasser",
            "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
            "teaching_major": "computer_science",
            "bio": "Backend developer and teacher."
        },
        "rating_average": null,
        "ratings_count": 0,
        "sections": [
            {
                "id": 1,
                "title": "Getting started",
                "description": null,
                "sort_order": 1,
                "lessons": [
                    {
                        "id": 1,
                        "title": "Installing Laravel",
                        "description": "Lesson description.",
                        "duration_seconds": 420,
                        "sort_order": 1,
                        "completed": true
                    },
                    {
                        "id": 2,
                        "title": "Routing",
                        "description": "Lesson description.",
                        "duration_seconds": 610,
                        "sort_order": 2,
                        "completed": false
                    }
                ]
            }
        ]
    }
}
```

<a id="example-25-my-courses"></a>
## 25. My courses

`GET /api/student/my-courses`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Enrolled courses retrieved successfully.",
    "courses": [
        {
            "id": 1,
            "title": "Laravel Basics",
            "description": "Build REST APIs with Laravel.",
            "price": "0.00",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "easy",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "published",
            "duration_hours": null,
            "is_enrolled": true,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Khaled",
                "father_name": "Samir",
                "last_name": "Nasser",
                "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
                "teaching_major": "computer_science",
                "bio": "Backend developer and teacher."
            },
            "rating_average": null,
            "ratings_count": 0,
            "sections": [
                {
                    "id": 1,
                    "title": "Getting started",
                    "description": null,
                    "sort_order": 1,
                    "lessons": [
                        {
                            "id": 1,
                            "title": "Installing Laravel",
                            "description": "Lesson description.",
                            "duration_seconds": 420,
                            "sort_order": 1,
                            "completed": true
                        },
                        {
                            "id": 2,
                            "title": "Routing",
                            "description": "Lesson description.",
                            "duration_seconds": 610,
                            "sort_order": 2,
                            "completed": false
                        }
                    ]
                }
            ],
            "enrolled_at": "2026-10-24T10:00:00.000000Z",
            "last_lesson_id": 1,
            "progress": {
                "completed_lessons": 1,
                "total_lessons": 2,
                "percent": 50
            },
            "my_rating": null
        }
    ],
    "data": [
        {
            "id": 1,
            "title": "Laravel Basics",
            "description": "Build REST APIs with Laravel.",
            "price": "0.00",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "easy",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "published",
            "duration_hours": null,
            "is_enrolled": true,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Khaled",
                "father_name": "Samir",
                "last_name": "Nasser",
                "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
                "teaching_major": "computer_science",
                "bio": "Backend developer and teacher."
            },
            "rating_average": null,
            "ratings_count": 0,
            "sections": [
                {
                    "id": 1,
                    "title": "Getting started",
                    "description": null,
                    "sort_order": 1,
                    "lessons": [
                        {
                            "id": 1,
                            "title": "Installing Laravel",
                            "description": "Lesson description.",
                            "duration_seconds": 420,
                            "sort_order": 1,
                            "completed": true
                        },
                        {
                            "id": 2,
                            "title": "Routing",
                            "description": "Lesson description.",
                            "duration_seconds": 610,
                            "sort_order": 2,
                            "completed": false
                        }
                    ]
                }
            ],
            "enrolled_at": "2026-10-24T10:00:00.000000Z",
            "last_lesson_id": 1,
            "progress": {
                "completed_lessons": 1,
                "total_lessons": 2,
                "percent": 50
            },
            "my_rating": null
        }
    ]
}
```

<a id="example-26-rate-a-course"></a>
## 26. Rate a course

`POST /api/student/courses/1/rate`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "rating": 5,
    "comment": "Clear and practical."
}
```

Response `201`:

```json
{
    "message": "Course rated successfully.",
    "data": {
        "user_id": 3,
        "course_id": 1,
        "rating": 5,
        "comment": "Clear and practical.",
        "updated_at": "2026-10-24T10:00:00.000000Z",
        "created_at": "2026-10-24T10:00:00.000000Z",
        "id": 1
    }
}
```

<a id="example-27-rate-a-course-a-second-time"></a>
## 27. Rate a course a second time (409, use PUT to change it)

`POST /api/student/courses/1/rate`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "rating": 3
}
```

Response `409`:

```json
{
    "message": "You have already rated this course."
}
```

<a id="example-28-update-my-course-rating"></a>
## 28. Update my course rating

`PUT /api/student/courses/1/rate`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "rating": 4,
    "comment": "Clear, a bit fast in places."
}
```

Response `200`:

```json
{
    "message": "Course rating updated successfully.",
    "data": {
        "id": 1,
        "user_id": 3,
        "course_id": 1,
        "rating": 4,
        "comment": "Clear, a bit fast in places.",
        "created_at": "2026-10-24T10:00:00.000000Z",
        "updated_at": "2026-10-24T10:00:00.000000Z"
    }
}
```

<a id="example-29-update-a-rating-that-does-not-exist-yet"></a>
## 29. Update a rating that does not exist yet (404)

`PUT /api/student/courses/1/rate-instructor`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "rating": 5
}
```

Response `404`:

```json
{
    "message": "You have not rated this instructor yet."
}
```

<a id="example-30-rate-the-instructor-of-a-course"></a>
## 30. Rate the instructor of a course

`POST /api/student/courses/1/rate-instructor`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "rating": 5,
    "comment": "Explains well."
}
```

Response `201`:

```json
{
    "message": "Instructor rated successfully.",
    "data": {
        "user_id": 3,
        "instructor_profile_id": 1,
        "rating": 5,
        "comment": "Explains well.",
        "updated_at": "2026-10-24T10:00:00.000000Z",
        "created_at": "2026-10-24T10:00:00.000000Z",
        "id": 1
    }
}
```

<a id="example-31-update-my-instructor-rating"></a>
## 31. Update my instructor rating

`PUT /api/student/courses/1/rate-instructor`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "rating": 4,
    "comment": null
}
```

Response `200`:

```json
{
    "message": "Instructor rating updated successfully.",
    "data": {
        "id": 1,
        "user_id": 3,
        "instructor_profile_id": 1,
        "rating": 4,
        "comment": null,
        "created_at": "2026-10-24T10:00:00.000000Z",
        "updated_at": "2026-10-24T10:00:00.000000Z"
    }
}
```

<a id="example-32-course-ratings"></a>
## 32. Course ratings (guest, my_rating is null)

`GET /api/courses/1/ratings`

- Auth: none

Response `200`:

```json
{
    "course_id": 1,
    "average_rating": 4,
    "ratings_count": 1,
    "message": "Course ratings retrieved successfully.",
    "data": {
        "average": 4,
        "count": 1,
        "ratings": [
            {
                "id": 1,
                "rating": 4,
                "comment": "Clear, a bit fast in places.",
                "student": {
                    "first_name": "Lina",
                    "last_name": "Haddad"
                },
                "created_at": "2026-10-24T10:00:00.000000Z"
            }
        ],
        "my_rating": null
    }
}
```

<a id="example-33-course-ratings"></a>
## 33. Course ratings (student token, with my_rating)

`GET /api/courses/1/ratings`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "course_id": 1,
    "average_rating": 4,
    "ratings_count": 1,
    "message": "Course ratings retrieved successfully.",
    "data": {
        "average": 4,
        "count": 1,
        "ratings": [
            {
                "id": 1,
                "rating": 4,
                "comment": "Clear, a bit fast in places.",
                "student": {
                    "first_name": "Lina",
                    "last_name": "Haddad"
                },
                "created_at": "2026-10-24T10:00:00.000000Z"
            }
        ],
        "my_rating": {
            "id": 1,
            "rating": 4,
            "comment": "Clear, a bit fast in places.",
            "student": {
                "first_name": "Lina",
                "last_name": "Haddad"
            },
            "created_at": "2026-10-24T10:00:00.000000Z"
        }
    }
}
```

<a id="example-34-instructor-ratings"></a>
## 34. Instructor ratings (student token, with my_rating)

`GET /api/instructors/1/ratings`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "instructor_profile_id": 1,
    "average_rating": 4,
    "ratings_count": 1,
    "message": "Instructor ratings retrieved successfully.",
    "data": {
        "average": 4,
        "count": 1,
        "ratings": [
            {
                "id": 1,
                "rating": 4,
                "comment": null,
                "student": {
                    "first_name": "Lina",
                    "last_name": "Haddad"
                },
                "created_at": "2026-10-24T10:00:00.000000Z"
            }
        ],
        "my_rating": {
            "id": 1,
            "rating": 4,
            "comment": null,
            "student": {
                "first_name": "Lina",
                "last_name": "Haddad"
            },
            "created_at": "2026-10-24T10:00:00.000000Z"
        }
    }
}
```

<a id="example-35-update-the-student-profile"></a>
## 35. Update the student profile (JSON)

`PUT /api/student/profile`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "first_name": "Maya",
    "father_name": "Nabil",
    "last_name": "Aziz",
    "phone": "0933111222",
    "gender": "female",
    "birthday": "2001-02-03"
}
```

Response `200`:

```json
{
    "message": "Student profile updated successfully.",
    "user": {
        "id": 3,
        "phone": "0933111222",
        "email": "student@example.test",
        "gender": "female",
        "age": 25,
        "role": "student",
        "status": "active",
        "profile": {
            "type": "student",
            "id": 1,
            "first_name": "Maya",
            "father_name": "Nabil",
            "last_name": "Aziz",
            "avatar": null
        }
    },
    "data": {
        "id": 3,
        "phone": "0933111222",
        "email": "student@example.test",
        "gender": "female",
        "age": 25,
        "role": "student",
        "status": "active",
        "profile": {
            "type": "student",
            "id": 1,
            "first_name": "Maya",
            "father_name": "Nabil",
            "last_name": "Aziz",
            "avatar": null
        }
    }
}
```

<a id="example-36-update-the-instructor-profile"></a>
## 36. Update the instructor profile (multipart POST with _method=PUT)

`POST /api/instructor/profile`

- Auth: `Authorization: Bearer <token>`
- Body (multipart/form-data):

```json
{
    "_method": "PUT",
    "first_name": "Omar",
    "father_name": "Tarek",
    "last_name": "Saeed",
    "phone": "0933444555",
    "gender": "male",
    "birthday": "1988-08-08",
    "teaching_major": "software_engineering",
    "bio": "Backend developer and teacher.",
    "avatar": "<file: avatar.png>"
}
```

Response `200`:

```json
{
    "message": "Instructor profile updated successfully.",
    "user": {
        "id": 2,
        "phone": "0933444555",
        "email": "instructor@example.test",
        "gender": "male",
        "age": 38,
        "role": "instructor",
        "status": "active",
        "reject_reason": null,
        "profile": {
            "type": "instructor",
            "id": 1,
            "first_name": "Omar",
            "father_name": "Tarek",
            "last_name": "Saeed",
            "avatar": "https://api.example.test/instructors/avatars/example14xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792837800",
            "teaching_major": "software_engineering",
            "bio": "Backend developer and teacher.",
            "certifications": []
        }
    },
    "data": {
        "id": 2,
        "phone": "0933444555",
        "email": "instructor@example.test",
        "gender": "male",
        "age": 38,
        "role": "instructor",
        "status": "active",
        "reject_reason": null,
        "profile": {
            "type": "instructor",
            "id": 1,
            "first_name": "Omar",
            "father_name": "Tarek",
            "last_name": "Saeed",
            "avatar": "https://api.example.test/instructors/avatars/example14xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792837800",
            "teaching_major": "software_engineering",
            "bio": "Backend developer and teacher.",
            "certifications": []
        }
    }
}
```

<a id="example-37-forgot-password"></a>
## 37. Forgot password (sends the reset mail)

`POST /api/auth/forgot-password`

- Auth: none
- Body (JSON):

```json
{
    "email": "student@example.test"
}
```

Response `200`:

```json
{
    "message": "If the email is registered, a password reset link will be sent."
}
```

Link in the mail (token redacted): `https://frontend.example.test/reset-password?token=<token>&email=student%40example.test`

<a id="example-38-platform-statistics"></a>
## 38. Platform statistics (public)

`GET /api/stats`

- Auth: none

Response `200`:

```json
{
    "message": "Statistics retrieved successfully.",
    "data": {
        "students": 2,
        "courses": 3,
        "instructors": 1,
        "lessons": 3
    }
}
```

<a id="example-39-admin-statistics"></a>
## 39. Admin statistics

`GET /api/admin/stats`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Admin statistics retrieved successfully.",
    "data": {
        "users_by_role_and_status": {
            "admin": {
                "active": 1,
                "inactive": 0,
                "blocked": 0,
                "deleted": 0,
                "pending": 0,
                "total": 1
            },
            "instructor": {
                "active": 1,
                "inactive": 0,
                "blocked": 0,
                "deleted": 0,
                "pending": 0,
                "total": 1
            },
            "student": {
                "active": 1,
                "inactive": 1,
                "blocked": 0,
                "deleted": 0,
                "pending": 0,
                "total": 2
            }
        },
        "courses_by_status": {
            "draft": 0,
            "pending_publish": 0,
            "published": 3,
            "rejected": 2,
            "archived": 0,
            "total": 5
        },
        "pending_instructor_requests": 0,
        "pending_courses": 0
    }
}
```

<a id="example-40-missing-record"></a>
## 40. Missing record (404 for any missing model)

`GET /api/admin/users/999`

- Auth: `Authorization: Bearer <token>`

Response `404`:

```json
{
    "message": "User not found."
}
```

<a id="example-41-course-catalog-paginated"></a>
## 41. Course catalog, paginated

`GET /api/student/courses?page=1&per_page=2`

- Auth: none

Response `200`:

```json
{
    "message": "Courses retrieved successfully.",
    "data": [
        {
            "id": 5,
            "title": "Testing Laravel APIs (2nd edition)",
            "description": "Feature tests for REST APIs.",
            "price": "35.50",
            "thumbnail": "https://api.example.test/courses/thumbnails/example12xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "medium",
            "what_you_will_learn": "HTTP tests, factories and fakes.",
            "status": "published",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Omar",
                "father_name": "Tarek",
                "last_name": "Saeed",
                "avatar": "https://api.example.test/instructors/avatars/example14xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792837800",
                "teaching_major": "software_engineering",
                "bio": "Backend developer and teacher."
            },
            "rating_average": null,
            "ratings_count": 0
        },
        {
            "id": 2,
            "title": "Advanced Eloquent",
            "description": "Build REST APIs with Laravel.",
            "price": "49.99",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "hard",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "published",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Omar",
                "father_name": "Tarek",
                "last_name": "Saeed",
                "avatar": "https://api.example.test/instructors/avatars/example14xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792837800",
                "teaching_major": "software_engineering",
                "bio": "Backend developer and teacher."
            },
            "rating_average": null,
            "ratings_count": 0
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 2,
        "total": 3,
        "per_page": 2
    }
}
```

<a id="example-42-my-courses"></a>
## 42. My courses (instructor), paginated

`GET /api/instructor/courses?page=1&per_page=2`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Courses retrieved successfully.",
    "data": [
        {
            "id": 5,
            "title": "Testing Laravel APIs (2nd edition)",
            "description": "Feature tests for REST APIs.",
            "price": "35.50",
            "thumbnail": "https://api.example.test/courses/thumbnails/example12xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "medium",
            "what_you_will_learn": "HTTP tests, factories and fakes.",
            "status": "published",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Omar",
                "father_name": "Tarek",
                "last_name": "Saeed",
                "avatar": "https://api.example.test/instructors/avatars/example14xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792837800",
                "teaching_major": "software_engineering",
                "bio": "Backend developer and teacher."
            },
            "students_count": 0,
            "reject_reason": null,
            "rating_average": null,
            "ratings_count": 0
        },
        {
            "id": 4,
            "title": "Draft Ideas",
            "description": "Build REST APIs with Laravel.",
            "price": "0.00",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "easy",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "rejected",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Omar",
                "father_name": "Tarek",
                "last_name": "Saeed",
                "avatar": "https://api.example.test/instructors/avatars/example14xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792837800",
                "teaching_major": "software_engineering",
                "bio": "Backend developer and teacher."
            },
            "students_count": 0,
            "reject_reason": "The audio in section 2 is too quiet.",
            "rating_average": null,
            "ratings_count": 0
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 3,
        "total": 5,
        "per_page": 2
    }
}
```

<a id="example-43-list-courses"></a>
## 43. List courses (admin), paginated

`GET /api/admin/courses?page=2&per_page=2`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Courses retrieved successfully.",
    "data": [
        {
            "id": 3,
            "title": "Vue Crash Course",
            "description": "Build REST APIs with Laravel.",
            "price": "0.00",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "easy",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "rejected",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Omar",
                "father_name": "Tarek",
                "last_name": "Saeed",
                "avatar": "https://api.example.test/instructors/avatars/example14xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792837800",
                "teaching_major": "software_engineering",
                "bio": "Backend developer and teacher."
            },
            "students_count": 0,
            "rating_average": null,
            "ratings_count": 0
        },
        {
            "id": 2,
            "title": "Advanced Eloquent",
            "description": "Build REST APIs with Laravel.",
            "price": "49.99",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "hard",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "published",
            "duration_hours": null,
            "is_enrolled": false,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Omar",
                "father_name": "Tarek",
                "last_name": "Saeed",
                "avatar": "https://api.example.test/instructors/avatars/example14xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792837800",
                "teaching_major": "software_engineering",
                "bio": "Backend developer and teacher."
            },
            "students_count": 0,
            "rating_average": null,
            "ratings_count": 0
        }
    ],
    "meta": {
        "current_page": 2,
        "last_page": 3,
        "total": 5,
        "per_page": 2
    }
}
```

<a id="example-44-reject-an-instructor-application-with-an-optional-reason"></a>
## 44. Reject an instructor application with an optional reason (admin)

`PATCH /api/admin/instructor-requests/5/reject`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "reason": "The uploaded certificate is not readable."
}
```

Response `200`:

```json
{
    "message": "Instructor application rejected successfully."
}
```

<a id="example-45-login"></a>
## 45. Login (rejected instructor, with reject_reason)

`POST /api/auth/login`

- Auth: none
- Body (JSON):

```json
{
    "email": "applicant@example.test",
    "password": "secret-password"
}
```

Response `200`:

```json
{
    "message": "Login successful.",
    "user": {
        "id": 5,
        "phone": "0900000005",
        "email": "applicant@example.test",
        "gender": "male",
        "age": 31,
        "role": "instructor",
        "status": "inactive",
        "reject_reason": "The uploaded certificate is not readable.",
        "profile": {
            "type": "instructor",
            "id": 2,
            "first_name": "Nour",
            "father_name": "Samir",
            "last_name": "Hamdan",
            "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
            "teaching_major": "computer_science",
            "bio": "Backend developer and teacher.",
            "certifications": []
        }
    },
    "token": "<token>"
}
```

<a id="example-46-send-a-contact-message"></a>
## 46. Send a contact message (public, 5 per minute)

`POST /api/contact`

- Auth: none
- Body (JSON):

```json
{
    "name": "Sara Khalil",
    "email": "sara@example.test",
    "message": "Do you offer group discounts?"
}
```

Response `201`:

```json
{
    "message": "Your message has been sent."
}
```

<a id="example-47-contact-messages"></a>
## 47. Contact messages (admin)

`GET /api/admin/contact-messages`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Contact messages retrieved successfully.",
    "data": [
        {
            "id": 1,
            "name": "Sara Khalil",
            "email": "sara@example.test",
            "message": "Do you offer group discounts?",
            "is_read": false,
            "read_at": null,
            "created_at": "2026-10-24T10:00:00.000000Z"
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "total": 1,
        "per_page": 15
    }
}
```

<a id="example-48-mark-a-contact-message-as-read"></a>
## 48. Mark a contact message as read (admin)

`PATCH /api/admin/contact-messages/1/read`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Contact message marked as read.",
    "data": {
        "id": 1,
        "name": "Sara Khalil",
        "email": "sara@example.test",
        "message": "Do you offer group discounts?",
        "is_read": true,
        "read_at": "2026-10-24T10:00:00.000000Z",
        "created_at": "2026-10-24T10:00:00.000000Z"
    }
}
```

<a id="example-49-reorder-the-sections-of-a-course"></a>
## 49. Reorder the sections of a course (instructor)

`PATCH /api/instructor/courses/6/sections/reorder`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "ids": [
        4,
        3
    ]
}
```

Response `200`:

```json
{
    "message": "Sections reordered successfully."
}
```

<a id="example-50-reorder-the-lessons-of-a-section"></a>
## 50. Reorder the lessons of a section (instructor)

`PATCH /api/instructor/sections/3/lessons/reorder`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "ids": [
        5,
        4
    ]
}
```

Response `200`:

```json
{
    "message": "Lessons reordered successfully."
}
```

<a id="example-51-reorder-with-ids-that-are-not-exactly-the-section-lessons"></a>
## 51. Reorder with ids that are not exactly the section lessons (422)

`PATCH /api/instructor/sections/3/lessons/reorder`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "ids": [
        4
    ]
}
```

Response `422`:

```json
{
    "message": "The ids must list every lesson of this section exactly once.",
    "errors": {
        "ids": [
            "The ids must list every lesson of this section exactly once."
        ]
    }
}
```

<a id="example-52-add-a-section-to-a-published-course"></a>
## 52. Add a section to a published course (422)

`POST /api/instructor/courses/1/sections`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "title": "Bonus"
}
```

Response `422`:

```json
{
    "message": "Sections and lessons can only be changed while the course is draft or rejected."
}
```

<a id="example-53-update-a-published-course"></a>
## 53. Update a published course (422)

`PUT /api/instructor/courses/1`

- Auth: `Authorization: Bearer <token>`
- Body (JSON):

```json
{
    "title": "Laravel Basics (updated)"
}
```

Response `422`:

```json
{
    "message": "Only draft or rejected courses can be updated."
}
```

<a id="example-54-delete-a-course-that-is-not-a-draft"></a>
## 54. Delete a course that is not a draft (422)

`DELETE /api/instructor/courses/1`

- Auth: `Authorization: Bearer <token>`

Response `422`:

```json
{
    "message": "Only draft courses can be deleted."
}
```

<a id="example-55-replace-the-video-of-a-lesson"></a>
## 55. Replace the video of a lesson (multipart; draft or rejected course; processing is queued)

`POST /api/instructor/lessons/4/video`

- Auth: `Authorization: Bearer <token>`
- Body (multipart/form-data):

```json
{
    "video": "<file: lesson-v2.mp4>"
}
```

Response `200`:

```json
{
    "message": "Lesson video replaced successfully. Video processing started.",
    "lesson": {
        "id": 4,
        "section_id": 3,
        "title": "Images and containers",
        "description": "Lesson description.",
        "duration_seconds": 0,
        "sort_order": 2,
        "video_status": "processing",
        "video": {
            "id": 4,
            "status": "processing",
            "playlist_path": null,
            "duration_seconds": 0
        }
    }
}
```

<a id="example-56-resubmit-a-rejected-course"></a>
## 56. Resubmit a rejected course (clears reject_reason)

`POST /api/instructor/courses/4/submit`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Course submitted for publishing successfully.",
    "status": "pending_publish"
}
```

<a id="example-57-re-apply-as-a-rejected-instructor"></a>
## 57. Re-apply as a rejected instructor (same email, multipart; back to pending)

`POST /api/auth/register-instructor`

- Auth: none
- Body (multipart/form-data):

```json
{
    "first_name": "Nour",
    "father_name": "Walid",
    "last_name": "Hamdan",
    "gender": "female",
    "birthday": "1992-04-12",
    "phone": "0900000005",
    "email": "applicant@example.test",
    "password": "another-password",
    "password_confirmation": "another-password",
    "teaching_major": "data_science",
    "bio": "Data analyst, re-applying with a clearer certificate.",
    "certifications": [
        "<file: certificate.png>"
    ]
}
```

Response `201`:

```json
{
    "message": "Your instructor registration is pending admin approval.",
    "instructor": {
        "id": 5,
        "phone": "0900000005",
        "email": "applicant@example.test",
        "gender": "female",
        "age": 34,
        "role": "instructor",
        "status": "pending",
        "reject_reason": null,
        "profile": {
            "type": "instructor",
            "id": 2,
            "first_name": "Nour",
            "father_name": "Walid",
            "last_name": "Hamdan",
            "avatar": "https://api.example.test/instructors/avatars/example.png?expiration=1792837800",
            "teaching_major": "data_science",
            "bio": "Data analyst, re-applying with a clearer certificate.",
            "certifications": [
                {
                    "id": 1,
                    "certification": "https://api.example.test/instructors/certificates/example20xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792837800"
                }
            ]
        }
    }
}
```

<a id="example-58-register-as-instructor-with-an-email-that-is-already-used"></a>
## 58. Register as instructor with an email that is already used (422)

`POST /api/auth/register-instructor`

- Auth: none
- Body (JSON):

```json
{
    "first_name": "Hadi",
    "father_name": "Karim",
    "last_name": "Mansour",
    "gender": "male",
    "birthday": "1990-01-01",
    "phone": "0900000099",
    "email": "student@example.test",
    "password": "another-password",
    "password_confirmation": "another-password",
    "teaching_major": "physics"
}
```

Response `422`:

```json
{
    "message": "The email has already been taken.",
    "errors": {
        "email": [
            "The email has already been taken."
        ]
    }
}
```

<a id="example-59-teaching-majors"></a>
## 59. Teaching majors (public)

`GET /api/teaching-majors`

- Auth: none

Response `200`:

```json
{
    "data": [
        "computer_science",
        "information_technology",
        "software_engineering",
        "data_science",
        "artificial_intelligence",
        "cyber_security",
        "networking",
        "business",
        "marketing",
        "accounting",
        "finance",
        "mathematics",
        "physics",
        "chemistry",
        "biology",
        "languages",
        "design"
    ]
}
```

<a id="example-60-list-users"></a>
## 60. List users (admin), filtered and paginated

`GET /api/admin/users?role=student&page=1&per_page=1`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "data": [
        {
            "id": 4,
            "phone": "0900000004",
            "email": "student2@example.test",
            "gender": "male",
            "age": 31,
            "role": "student",
            "status": "inactive",
            "profile": {
                "type": "student",
                "id": 2,
                "first_name": "Rami",
                "father_name": "Fadi",
                "last_name": "Saleh",
                "avatar": null
            }
        }
    ],
    "links": {
        "first": "https://api.example.test/api/admin/users?role=student&per_page=1&page=1",
        "last": "https://api.example.test/api/admin/users?role=student&per_page=1&page=2",
        "prev": null,
        "next": "https://api.example.test/api/admin/users?role=student&per_page=1&page=2"
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 2,
        "links": [
            {
                "url": null,
                "label": "&laquo; Previous",
                "page": null,
                "active": false
            },
            {
                "url": "https://api.example.test/api/admin/users?role=student&per_page=1&page=1",
                "label": "1",
                "page": 1,
                "active": true
            },
            {
                "url": "https://api.example.test/api/admin/users?role=student&per_page=1&page=2",
                "label": "2",
                "page": 2,
                "active": false
            },
            {
                "url": "https://api.example.test/api/admin/users?role=student&per_page=1&page=2",
                "label": "Next &raquo;",
                "page": 2,
                "active": false
            }
        ],
        "path": "https://api.example.test/api/admin/users",
        "per_page": 1,
        "to": 1,
        "total": 2
    }
}
```

<a id="example-61-list-payments"></a>
## 61. List payments (admin)

`GET /api/admin/payments`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "data": [
        {
            "id": 1,
            "student": {
                "id": 3,
                "name": "Maya Aziz"
            },
            "course": {
                "id": 2,
                "title": "Advanced Eloquent"
            },
            "amount": "49.99",
            "currency": "USD",
            "status": "processing",
            "provider": "stripe",
            "payment_method": "card",
            "provider_payment_id": null,
            "stripe_checkout_session_id": "cs_test_example",
            "paid_at": null,
            "created_at": "2026-10-24T10:00:00.000000Z"
        }
    ]
}
```

<a id="example-62-list-payments"></a>
## 62. List payments (admin), paginated

`GET /api/admin/payments?page=1&per_page=1`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "data": [
        {
            "id": 1,
            "student": {
                "id": 3,
                "name": "Maya Aziz"
            },
            "course": {
                "id": 2,
                "title": "Advanced Eloquent"
            },
            "amount": "49.99",
            "currency": "USD",
            "status": "processing",
            "provider": "stripe",
            "payment_method": "card",
            "provider_payment_id": null,
            "stripe_checkout_session_id": "cs_test_example",
            "paid_at": null,
            "created_at": "2026-10-24T10:00:00.000000Z"
        }
    ],
    "links": {
        "first": "https://api.example.test/api/admin/payments?per_page=1&page=1",
        "last": "https://api.example.test/api/admin/payments?per_page=1&page=1",
        "prev": null,
        "next": null
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 1,
        "links": [
            {
                "url": null,
                "label": "&laquo; Previous",
                "page": null,
                "active": false
            },
            {
                "url": "https://api.example.test/api/admin/payments?per_page=1&page=1",
                "label": "1",
                "page": 1,
                "active": true
            },
            {
                "url": null,
                "label": "Next &raquo;",
                "page": null,
                "active": false
            }
        ],
        "path": "https://api.example.test/api/admin/payments",
        "per_page": 1,
        "to": 1,
        "total": 1
    }
}
```

<a id="example-63-course-by-id-with-reject-reason"></a>
## 63. Course by id with reject_reason (admin)

`GET /api/admin/courses/3`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "course": {
        "id": 3,
        "title": "Vue Crash Course",
        "description": "Build REST APIs with Laravel.",
        "price": "0.00",
        "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
        "language": "English",
        "difficulty_level": "easy",
        "what_you_will_learn": "Routing, Eloquent and testing.",
        "status": "rejected",
        "duration_hours": null,
        "is_enrolled": false,
        "category": {
            "id": 1,
            "name": "Programming"
        },
        "instructor": {
            "id": 1,
            "first_name": "Omar",
            "father_name": "Tarek",
            "last_name": "Saeed",
            "avatar": "https://api.example.test/instructors/avatars/example14xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792837800",
            "teaching_major": "software_engineering",
            "bio": "Backend developer and teacher."
        },
        "students_count": 0,
        "reject_reason": "Please add a short introduction lesson.",
        "rating_average": null,
        "ratings_count": 0,
        "sections": []
    }
}
```

<a id="example-64-my-courses-with-my-rating"></a>
## 64. My courses, with my_rating

`GET /api/student/my-courses`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Enrolled courses retrieved successfully.",
    "courses": [
        {
            "id": 1,
            "title": "Laravel Basics",
            "description": "Build REST APIs with Laravel.",
            "price": "0.00",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "easy",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "published",
            "duration_hours": null,
            "is_enrolled": true,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Omar",
                "father_name": "Tarek",
                "last_name": "Saeed",
                "avatar": "https://api.example.test/instructors/avatars/example14xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792837800",
                "teaching_major": "software_engineering",
                "bio": "Backend developer and teacher."
            },
            "rating_average": 4,
            "ratings_count": 1,
            "sections": [
                {
                    "id": 1,
                    "title": "Getting started",
                    "description": null,
                    "sort_order": 1,
                    "lessons": [
                        {
                            "id": 1,
                            "title": "Installing Laravel",
                            "description": "Lesson description.",
                            "duration_seconds": 420,
                            "sort_order": 1,
                            "completed": true
                        },
                        {
                            "id": 2,
                            "title": "Routing",
                            "description": "Lesson description.",
                            "duration_seconds": 610,
                            "sort_order": 2,
                            "completed": false
                        }
                    ]
                }
            ],
            "enrolled_at": "2026-10-24T10:00:00.000000Z",
            "last_lesson_id": 1,
            "progress": {
                "completed_lessons": 1,
                "total_lessons": 2,
                "percent": 50
            },
            "my_rating": 4
        }
    ],
    "data": [
        {
            "id": 1,
            "title": "Laravel Basics",
            "description": "Build REST APIs with Laravel.",
            "price": "0.00",
            "thumbnail": "https://api.example.test/courses/thumbnails/example.png?expiration=1792896000",
            "language": "English",
            "difficulty_level": "easy",
            "what_you_will_learn": "Routing, Eloquent and testing.",
            "status": "published",
            "duration_hours": null,
            "is_enrolled": true,
            "category": {
                "id": 1,
                "name": "Programming"
            },
            "instructor": {
                "id": 1,
                "first_name": "Omar",
                "father_name": "Tarek",
                "last_name": "Saeed",
                "avatar": "https://api.example.test/instructors/avatars/example14xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.png?expiration=1792837800",
                "teaching_major": "software_engineering",
                "bio": "Backend developer and teacher."
            },
            "rating_average": 4,
            "ratings_count": 1,
            "sections": [
                {
                    "id": 1,
                    "title": "Getting started",
                    "description": null,
                    "sort_order": 1,
                    "lessons": [
                        {
                            "id": 1,
                            "title": "Installing Laravel",
                            "description": "Lesson description.",
                            "duration_seconds": 420,
                            "sort_order": 1,
                            "completed": true
                        },
                        {
                            "id": 2,
                            "title": "Routing",
                            "description": "Lesson description.",
                            "duration_seconds": 610,
                            "sort_order": 2,
                            "completed": false
                        }
                    ]
                }
            ],
            "enrolled_at": "2026-10-24T10:00:00.000000Z",
            "last_lesson_id": 1,
            "progress": {
                "completed_lessons": 1,
                "total_lessons": 2,
                "percent": 50
            },
            "my_rating": 4
        }
    ]
}
```
