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

    public function test_enrolled_student_rates_a_course_and_rating_again_updates_it(): void
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

        $this->postJson("/api/student/courses/{$course->id}/rate", ['rating' => 2, 'comment' => 'Changed my mind'])
            ->assertOk()
            ->assertJsonPath('message', 'Course rating updated successfully.')
            ->assertJsonPath('data.rating', 2)
            ->assertJsonPath('data.comment', 'Changed my mind');

        $this->assertSame(1, CourseReview::count());
        $this->assertSame(2, CourseReview::first()->rating);
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
            ->assertOk()
            ->assertJsonPath('message', 'Instructor rating updated successfully.')
            ->assertJsonPath('data.rating', 3);

        $this->assertSame(1, InstructorReview::count());
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
