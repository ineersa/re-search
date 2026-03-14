# PHPUnit via Mate

Use Mate PHPUnit tools for suite, file, or single-method execution.

## Tools

### `phpunit-list-tests`

- Lists discovered tests by file/class/method.
- Inputs:
  - `directory` (`string|null`): limit discovery to subtree.

### `phpunit-run-suite`

- Runs complete PHPUnit suite (optionally filtered).
- Inputs:
  - `configuration` (`string|null`): custom phpunit.xml path.
  - `filter` (`string|null`): test name regex filter.
  - `stopOnFailure` (`boolean`): stop at first failure.
  - `mode` (`default|summary|detailed|by-file|by-class`): output grouping.

### `phpunit-run-file`

- Runs tests from one file.
- Inputs:
  - `file` (`string`, required): test file path.
  - `filter` (`string|null`): method/class name filter.
  - `stopOnFailure` (`boolean`): stop on first failure.
  - `mode` (`default|summary|detailed`): output detail.

### `phpunit-run-method`

- Runs one test method in one class.
- Inputs:
  - `class` (`string`, required): fully-qualified or resolved test class.
  - `method` (`string`, required): method name.
  - `mode` (`default|summary|detailed`): output detail.

## Typical flow

1. Suite status:

```bash
scripts/mate-tool-call.sh phpunit-run-suite '{"mode":"summary"}'
```

2. Narrow by file:

```bash
scripts/mate-tool-call.sh phpunit-run-file '{"file":"tests/Service/MyServiceTest.php","mode":"default"}'
```

3. Isolate one method:

```bash
scripts/mate-tool-call.sh phpunit-run-method '{"class":"App\\Tests\\Service\\MyServiceTest","method":"testEdgeCase","mode":"detailed"}'
```

4. Discover names first (if unknown):

```bash
scripts/mate-tool-call.sh phpunit-list-tests '{}'
```

## Parameter guidance

- `mode`:
  - `summary`: pass/fail totals quickly.
  - `default`: includes key failure details.
  - `detailed`: full verbose failure payload.
  - `by-file`/`by-class`: useful for many failing tests.
- `filter`: pass PHPUnit filter regex when narrowing scope.
- `stopOnFailure`: speeds feedback loop for red-green debugging.
- `configuration`: set when using a non-default phpunit config file.
