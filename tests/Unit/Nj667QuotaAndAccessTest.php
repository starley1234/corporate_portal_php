<?php

use PHPUnit\Framework\TestCase;

/** Чистые проверки резолвера квот и видимости сборки NJ-667. */
class Nj667QuotaAndAccessTest extends TestCase
{
	public function test_individual_overrides_are_applied_by_the_resolver()
	{
		$limits = Service_Nj667_Graph3d::quota_limits_resolve(123, [
			'active_projects' => 30,
			'llm_operations_per_day' => 10,
		]);

		$this->assertSame(30, $limits['active_projects']);
		$this->assertSame(10, $limits['llm_operations_per_day']);
		$this->assertSame(3, $limits['historical_revisions']);
		$this->assertSame(30, $limits['build_parts_per_run']);
	}

	public function test_empty_overrides_inherit_registered_plan_defaults()
	{
		$limits = Service_Nj667_Graph3d::quota_limits_resolve(123, []);

		$this->assertSame(5, $limits['active_projects']);
		$this->assertSame(5, $limits['llm_operations_per_day']);
	}

	public function test_unpublished_build_of_public_project_requires_registration()
	{
		$project = ['id' => 7, 'user_id' => 99, 'status' => Service_Nj667_Graph3d::S_PUBLISHED];
		$build = ['project_id' => 7, 'published_at' => NULL];

		$this->assertFalse(Service_Nj667_Graph3d::can_read_build($project, $build, 0, '', FALSE));
		$this->assertTrue(Service_Nj667_Graph3d::can_read_build($project, $build, 123, '', TRUE));

		$build['published_at'] = '2026-10-15 10:00:00+00';
		$this->assertTrue(Service_Nj667_Graph3d::can_read_build($project, $build, 0, '', FALSE));
	}

	public function test_only_project_owner_or_registered_build_author_can_prepare_parts()
	{
		$project = ['id' => 7, 'user_id' => 99, 'status' => Service_Nj667_Graph3d::S_PUBLISHED];
		$build = ['project_id' => 7, 'user_id' => 123, 'published_at' => NULL];

		$this->assertTrue(Service_Nj667_Graph3d::can_prepare_build_parts($project, $build, 123, '', TRUE));
		$this->assertFalse(Service_Nj667_Graph3d::can_prepare_build_parts($project, $build, 456, '', TRUE));
		$this->assertFalse(Service_Nj667_Graph3d::can_prepare_build_parts($project, $build, 123, '', FALSE));
		$this->assertTrue(Service_Nj667_Graph3d::can_prepare_build_parts($project, $build, 99, '', TRUE));
		$this->assertFalse(Service_Nj667_Graph3d::can_prepare_build_parts(
			$project, ['project_id' => 8, 'user_id' => 123], 123, '', TRUE));
	}
}
