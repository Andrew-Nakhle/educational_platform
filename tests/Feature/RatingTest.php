<?php

namespace Tests\Feature;

use App\Models\CourseReview;
use App\Models\InstructorReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class RatingTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    public function test_course_ratings_are_public_and_keep_original_keys(): void
    {
        $course = $this->makeCourse();
        $first = $this->makeStudent(profile: ['first_name' => 'Rami', 'last_name' => 'Saleh']);
        $second = $this->makeStudent(profile: ['first_name' => 'Hala', 'last_name' => 'Yousef']);

        CourseReview::create(['user_id' => $first->id, 'course_id' => $course->id, 'rating' => 5, 'comment' => 'Great']);
        $this->travel(1)->minutes();
        CourseReview::create(['user_id' => $second->id, 'course_id' => $course->id, 'rating' => 2]);

        $this->getJson("/api/courses/{$course->id}/ratings")
            ->assertOk()
            ->assertJsonPath('course_id', $course->id)
            ->assertJsonPath('average_rating', 3.5)
            ->assertJsonPath('ratings_count', 2)
            ->assertJsonPath('message', 'Course ratings retrieved successfully.')
            ->assertJsonPath('data.average', 3.5)
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.ratings.0.rating', 2)
            ->assertJsonPath('data.ratings.0.comment', null)
            ->assertJsonPath('data.ratings.0.student', ['first_name' => 'Hala', 'last_name' => 'Yousef'])
            ->assertJsonPath('data.ratings.1.comment', 'Great')
            ->assertJsonStructure(['data' => ['ratings' => [['id', 'rating', 'comment', 'student', 'created_at']]]]);
    }

    public function test_course_without_ratings_has_null_average(): void
    {
        $course = $this->makeCourse();

        $this->getJson("/api/courses/{$course->id}/ratings")
            ->assertOk()
            ->assertJsonPath('average_rating', 0)
            ->assertJsonPath('data.average', null)
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.ratings', []);
    }

    public function test_instructor_ratings_have_same_shape(): void
    {
        $instructor = $this->makeInstructor();
        $student = $this->makeStudent();

        InstructorReview::create([
            'user_id' => $student->id,
            'instructor_profile_id' => $instructor->instructorProfile->id,
            'rating' => 4,
            'comment' => 'Clear explanations',
        ]);

        $this->getJson("/api/instructors/{$instructor->instructorProfile->id}/ratings")
            ->assertOk()
            ->assertJsonPath('instructor_profile_id', $instructor->instructorProfile->id)
            ->assertJsonPath('ratings_count', 1)
            ->assertJsonPath('message', 'Instructor ratings retrieved successfully.')
            ->assertJsonPath('data.average', 4)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.ratings.0.comment', 'Clear explanations')
            ->assertJsonPath('data.ratings.0.student.first_name', 'Lina');
    }

    public function test_ratings_return_404_for_missing_course_or_instructor(): void
    {
        $this->getJson('/api/courses/999/ratings')->assertNotFound();
        $this->getJson('/api/instructors/999/ratings')->assertNotFound();
    }

    public function test_ratings_include_my_rating_for_the_student_token_only(): void
    {
        $course = $this->makeCourse();
        $student = $this->makeStudent();
        $other = $this->makeStudent();

        CourseReview::create(['user_id' => $student->id, 'course_id' => $course->id, 'rating' => 4, 'comment' => 'Mine']);
        CourseReview::create(['user_id' => $other->id, 'course_id' => $course->id, 'rating' => 1]);
        InstructorReview::create([
            'user_id' => $student->id,
            'instructor_profile_id' => $course->instructor_profile_id,
            'rating' => 5,
        ]);

        // Guests: still public, my_rating is null.
        $this->getJson("/api/courses/{$course->id}/ratings")
            ->assertOk()
            ->assertJsonPath('data.my_rating', null);

        $this->withToken($this->tokenFor($student))
            ->getJson("/api/courses/{$course->id}/ratings")
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.my_rating.rating', 4)
            ->assertJsonPath('data.my_rating.comment', 'Mine')
            ->assertJsonStructure(['data' => ['my_rating' => ['id', 'rating', 'comment', 'student', 'created_at']]]);

        $this->withToken($this->tokenFor($student))
            ->getJson("/api/instructors/{$course->instructor_profile_id}/ratings")
            ->assertOk()
            ->assertJsonPath('data.my_rating.rating', 5);

        // A student without a rating, other roles and an invalid token.
        // (Guards are reset because the test reuses one application.)
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($this->makeStudent()))
            ->getJson("/api/courses/{$course->id}/ratings")
            ->assertJsonPath('data.my_rating', null);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($this->makeAdmin()))
            ->getJson("/api/courses/{$course->id}/ratings")
            ->assertJsonPath('data.my_rating', null);

        $this->app['auth']->forgetGuards();
        $this->withToken('invalid-token')
            ->getJson("/api/courses/{$course->id}/ratings")
            ->assertOk()
            ->assertJsonPath('data.my_rating', null);
    }

    public function test_enrolled_student_rates_a_course_once_then_updates_with_put(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum');

        $this->postJson("/api/student/courses/{$course->id}/rate", ['rating' => 4, 'comment' => 'Good'])
            ->assertCreated()
            ->assertJsonPath('message', 'Course rated successfully.')
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.course_id', $course->id);

        // A second POST keeps upstream's behaviour.
        $this->postJson("/api/student/courses/{$course->id}/rate", ['rating' => 2])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'You have already rated this course.']);

        $this->putJson("/api/student/courses/{$course->id}/rate", ['rating' => 2, 'comment' => 'Changed my mind'])
            ->assertOk()
            ->assertJsonPath('message', 'Course rating updated successfully.')
            ->assertJsonPath('data.rating', 2)
            ->assertJsonPath('data.comment', 'Changed my mind')
            ->assertJsonPath('data.course_id', $course->id);

        $this->putJson("/api/student/courses/{$course->id}/rate", ['rating' => 3])
            ->assertOk()
            ->assertJsonPath('data.comment', null);

        $this->assertSame(1, CourseReview::count());
        $this->assertSame(3, CourseReview::first()->rating);
    }

    public function test_updating_a_course_rating_needs_an_existing_rating_and_enrollment(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse();

        $this->putJson("/api/student/courses/{$course->id}/rate", ['rating' => 3])->assertUnauthorized();

        $this->actingAs($this->makeInstructor(), 'sanctum')
            ->putJson("/api/student/courses/{$course->id}/rate", ['rating' => 3])
            ->assertForbidden();

        $this->actingAs($student, 'sanctum');

        $this->putJson('/api/student/courses/999/rate', ['rating' => 3])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found.']);

        $this->putJson("/api/student/courses/{$course->id}/rate", ['rating' => 3])
            ->assertForbidden();

        $this->enroll($student, $course);

        $this->putJson("/api/student/courses/{$course->id}/rate", ['rating' => 3])
            ->assertNotFound()
            ->assertExactJson(['message' => 'You have not rated this course yet.']);

        $this->postJson("/api/student/courses/{$course->id}/rate", ['rating' => 5])->assertCreated();

        foreach ([0, 6, 'abc', null] as $rating) {
            $this->putJson("/api/student/courses/{$course->id}/rate", ['rating' => $rating])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['rating']);
        }

        $this->assertSame(5, CourseReview::first()->rating);
    }

    public function test_rating_a_course_requires_enrollment(): void
    {
        $course = $this->makeCourse();

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/rate", ['rating' => 5])
            ->assertForbidden();
    }

    public function test_rating_validates_range(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum');

        foreach ([0, 6, 'abc', null] as $rating) {
            $this->postJson("/api/student/courses/{$course->id}/rate", ['rating' => $rating])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['rating']);
        }
    }

    public function test_rating_requires_auth_student_and_existing_course(): void
    {
        $course = $this->makeCourse();

        $this->postJson("/api/student/courses/{$course->id}/rate", ['rating' => 5])->assertUnauthorized();

        $this->actingAs($this->makeInstructor(), 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/rate", ['rating' => 5])
            ->assertForbidden();

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson('/api/student/courses/999/rate', ['rating' => 5])
            ->assertNotFound();
    }

    public function test_enrolled_student_rates_the_instructor_once_and_can_update(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum');

        $this->postJson("/api/student/courses/{$course->id}/rate-instructor", ['rating' => 5])
            ->assertCreated()
            ->assertJsonPath('message', 'Instructor rated successfully.')
            ->assertJsonPath('data.instructor_profile_id', $course->instructor_profile_id);

        $this->postJson("/api/student/courses/{$course->id}/rate-instructor", ['rating' => 3])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'You have already rated this instructor.']);

        $this->putJson("/api/student/courses/{$course->id}/rate-instructor", ['rating' => 3, 'comment' => 'Okay'])
            ->assertOk()
            ->assertJsonPath('message', 'Instructor rating updated successfully.')
            ->assertJsonPath('data.rating', 3)
            ->assertJsonPath('data.comment', 'Okay')
            ->assertJsonPath('data.instructor_profile_id', $course->instructor_profile_id);

        $this->assertSame(1, InstructorReview::count());
        $this->assertSame(3, InstructorReview::first()->rating);
    }

    public function test_updating_an_instructor_rating_needs_an_existing_rating_and_enrollment(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse();

        $this->actingAs($student, 'sanctum');

        $this->putJson('/api/student/courses/999/rate-instructor', ['rating' => 3])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found.']);

        $this->putJson("/api/student/courses/{$course->id}/rate-instructor", ['rating' => 3])
            ->assertForbidden();

        $this->enroll($student, $course);

        $this->putJson("/api/student/courses/{$course->id}/rate-instructor", ['rating' => 3])
            ->assertNotFound()
            ->assertExactJson(['message' => 'You have not rated this instructor yet.']);

        $this->postJson("/api/student/courses/{$course->id}/rate-instructor", ['rating' => 4])->assertCreated();

        $this->putJson("/api/student/courses/{$course->id}/rate-instructor", ['rating' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rating']);
    }

    public function test_rating_an_instructor_requires_enrollment_and_valid_rating(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse();

        $this->actingAs($student, 'sanctum');

        $this->postJson("/api/student/courses/{$course->id}/rate-instructor", ['rating' => 5])
            ->assertForbidden();

        $this->enroll($student, $course);

        $this->postJson("/api/student/courses/{$course->id}/rate-instructor", ['rating' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rating']);
    }
}
