# Project guidelines

- Verify visibility of methods/properties, existence of constants/enums, and correct method names for interfaces/abstract classes
- Read PHPDoc and class definitions/signatures to identify correct types before instantiating
- Use constructors to pass required dependencies and data instead of setter methods, when available
- Follow PSR-4 autoloading defined in `composer.json` for code namespaces and autoload-dev for test namespaces

# Test-specific guidelines

- The code in the project's src folder is tested in the tests/Unit folder, keeping the same structure
- **Focus on outcomes, not implementation**:
  - Assertions must verify **observable behavior and side-effects** (the "what") rather than internal logic (the "how")
  - This ensures tests validate the public contract and remain resilient during refactoring
- **Minimize test count and avoid redundancy**:
  - Group all assertions that validate a complete logic flow into a single test instead of multiple granular ones
  - Do not create separate tests for behaviors already covered by existing assertions
  - **Avoid redundant test cases**: Do not add a new test unless it covers a unique failure scenario that existing tests would miss
  - Use **Data Providers** (with attributes) to test multiple scenarios and edge cases within the same test method, avoiding logic duplication
- Use class properties only for shared variables (e.g., initializing objects/mocks in `setUp`)
- **Assertion style**:
  - Use `self::` for assertions (e.g., `self::assertSame()`) instead of `$this->`
  - Assert only exception types, not messages
  - Do not write assertions on the return type of methods
- Mocking: omit `->with()` when returning fixed values via `willReturn()`; use it only if the return depends on arguments (e.g., `willReturnCallback()`)
- Fixtures: 
  - Use actual domain model classes instantiated with realistic data via proper constructors
  - Avoid generic/anonymous objects to ensure tests catch type-related issues
