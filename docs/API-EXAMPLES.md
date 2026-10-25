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
7. [Course by id (instructor)](#example-7-course-by-id) `GET /api/instructor/courses/4`
8. [Update a course (JSON; send multipart as POST with _method=PUT to change the thumbnail)](#example-8-update-a-course) `PUT /api/instructor/courses/4`
9. [Create a section](#example-9-create-a-section) `POST /api/instructor/courses/4/sections`
10. [Create a lesson with a video (multipart; processing is queued)](#example-10-create-a-lesson-with-a-video) `POST /api/instructor/sections/2/lessons`
11. [Submit a course for review](#example-11-submit-a-course-for-review) `POST /api/instructor/courses/4/submit`
12. [List users (admin)](#example-12-list-users) `GET /api/admin/users`
13. [User by id (admin)](#example-13-user-by-id) `GET /api/admin/users/3`
14. [Change a user status (admin)](#example-14-change-a-user-status) `PATCH /api/admin/users/4/status`
15. [List courses (admin)](#example-15-list-courses) `GET /api/admin/courses`
16. [Course by id (admin)](#example-16-course-by-id) `GET /api/admin/courses/1`
17. [Approve a course (admin)](#example-17-approve-a-course) `PATCH /api/admin/courses/4/approve`
18. [Reject a course (admin)](#example-18-reject-a-course) `PATCH /api/admin/courses/3/reject`
19. [Course details (guest, no token)](#example-19-course-details) `GET /api/student/courses/1`
20. [Enroll in a FREE course](#example-20-enroll-in-a-free-course) `POST /api/student/courses/1/enroll`
21. [Enroll in a PAID course (web, Stripe Checkout; Stripe is faked)](#example-21-enroll-in-a-paid-course) `POST /api/student/courses/2/enroll`
22. [Mark a lesson as completed](#example-22-mark-a-lesson-as-completed) `POST /api/student/lessons/1/complete`
23. [Course details (enrolled student)](#example-23-course-details) `GET /api/student/courses/1`
24. [My courses](#example-24-my-courses) `GET /api/student/my-courses`
25. [Rate a course](#example-25-rate-a-course) `POST /api/student/courses/1/rate`
26. [Platform statistics (public)](#example-26-platform-statistics) `GET /api/stats`

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
        "id": 4,
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
            "last_name": "Nasser"
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
                "last_name": "Nasser"
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
                "first_name": "Khaled",
                "father_name": "Samir",
                "last_name": "Nasser"
            },
            "rating_average": null,
            "ratings_count": 0
        },
        {
            "id": 3,
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
                "last_name": "Nasser"
            },
            "rating_average": null,
            "ratings_count": 0
        },
        {
            "id": 4,
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
                "last_name": "Nasser"
            },
            "rating_average": null,
            "ratings_count": 0
        }
    ]
}
```

<a id="example-7-course-by-id"></a>
## 7. Course by id (instructor)

`GET /api/instructor/courses/4`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "course": {
        "id": 4,
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
            "last_name": "Nasser"
        },
        "rating_average": null,
        "ratings_count": 0
    }
}
```

<a id="example-8-update-a-course"></a>
## 8. Update a course (JSON; send multipart as POST with _method=PUT to change the thumbnail)

`PUT /api/instructor/courses/4`

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
        "id": 4,
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
            "last_name": "Nasser"
        },
        "rating_average": null,
        "ratings_count": 0
    }
}
```

<a id="example-9-create-a-section"></a>
## 9. Create a section

`POST /api/instructor/courses/4/sections`

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
        "course_id": 4,
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

`POST /api/instructor/courses/4/submit`

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
            "id": 3,
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
                "last_name": "Nasser"
            },
            "rating_average": null,
            "ratings_count": 0
        },
        {
            "id": 4,
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
                "last_name": "Nasser"
            },
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
            "last_name": "Nasser"
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

`PATCH /api/admin/courses/4/approve`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Course approved and published successfully.",
    "status": "published"
}
```

<a id="example-18-reject-a-course"></a>
## 18. Reject a course (admin)

`PATCH /api/admin/courses/3/reject`

- Auth: `Authorization: Bearer <token>`

Response `200`:

```json
{
    "message": "Course rejected successfully.",
    "status": "rejected"
}
```

<a id="example-19-course-details"></a>
## 19. Course details (guest, no token)

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
            "last_name": "Nasser"
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

<a id="example-20-enroll-in-a-free-course"></a>
## 20. Enroll in a FREE course

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

<a id="example-21-enroll-in-a-paid-course"></a>
## 21. Enroll in a PAID course (web, Stripe Checkout; Stripe is faked)

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

<a id="example-22-mark-a-lesson-as-completed"></a>
## 22. Mark a lesson as completed

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

<a id="example-23-course-details"></a>
## 23. Course details (enrolled student)

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
            "last_name": "Nasser"
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

<a id="example-24-my-courses"></a>
## 24. My courses

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
                "last_name": "Nasser"
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
            }
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
                "last_name": "Nasser"
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
            }
        }
    ]
}
```

<a id="example-25-rate-a-course"></a>
## 25. Rate a course

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

<a id="example-26-platform-statistics"></a>
## 26. Platform statistics (public)

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
