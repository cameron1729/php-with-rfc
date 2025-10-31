# RFC: `with` Bindings for Arrow Functions

## Introduction

This RFC proposes adding optional `with (...)` bindings to PHP arrow functions.

It introduces a concise, expression-level way to define local variables directly on an arrow function, eliminating the need for multi-line anonymous functions to introduce a single local, or awkward inline assignments.

The design mirrors the placement and feel of `use (...)` but defines local variables as opposed to captured variables.

PHP is a multi-paradigm language; this proposal embraces practical ideas seen in other ecosystems (notably Haskell’s `where`) while adapting them to PHP’s eager, expression‑oriented arrows and familiar semantics.

Recent additions and proposals also show PHP embracing ideas from functional programming. Notably, the pipe operator (`|>`), the Partial Function Application v2 RFC (https://wiki.php.net/rfc/partial_function_application_v2), and the Function Composition RFC (https://wiki.php.net/rfc/function-composition). Expression‑level building blocks like `with` fit naturally alongside these.

## Motivation

Arrow functions are popular for short, expression-based callbacks. However, any non-trivial body today typically expands into a multi-line anonymous function just to introduce a single local:

```php
// Price filter with a single derived local.
function (stdClass $product): bool {
    $priceWithTax = $product->price * 1.2;
    return $priceWithTax > 4 && $priceWithTax < 10;
}
```

This is verbose compared to arrows. Alternatively, assignment inside the arrow expression is clunky or hacky:

```php
fn(stdClass $product): bool => ($price = $product->price * 1.2) > 4 && $price < 10; // easy to miss
```

Adding `with (...)` allows a clean, composable idiom:

```php
fn(stdClass $product): bool with ($price = $product->price * 1.2) => $price > 4 && $price < 10;
```

### Existing alternatives

Another workaround is to define a helper arrow function:

```php
$getPrice = fn(stdClass $product): float => $product->price * 1.2;
$products = array_filter($allProducts, fn($product): bool => $getPrice($product) > 4 && $getPrice($product) < 10);
```

With `with`, you can keep the computation local to the arrow function without introducing a helper:

```php
$products = array_filter(
    $allProducts,
    fn($product): bool with ($price = $product->price * 1.2) => $price > 4 && $price < 10
);
```

As mentioned, you can also rely on inline assignment inside expressions or array literals, but this tends to hide intent and is error prone:

```php
// Inline assignment in a condition works, but is easy to miss.
fn($product): bool => ($price = $product->price * 1.2) > 4 && $price < 10;

// Inline assignment inside an array literal; relies on evaluation order.
fn(User $u): array => [
    'full' => ($full = "{$u->first} {$u->last}"),
    'tag'  => strtoupper($u->first[0] . $u->last[0]),
    'desc' => "$full ({$u->last})",
];
```

`with` makes these intermediate values explicit, evaluated left‑to‑right before the body, without resorting to clever (and fragile) expression tricks.

## Proposal

### Syntax

Extend the arrow function syntax to optionally include a **`with` clause** between the parameter list and the `=>` token.

```php
fn(parameter_list) with (binding_list) => expression;
```

Each `binding` is an **assignment expression**:

```php
variable = expression
```

Notes:
- Bindings are separated by commas
- Trailing commas are allowed
- Destructuring patterns are permitted

### Examples

```php
fn(float $x, float $mean, float $sd): array with ($z = ($x - $mean) / $sd) => [
    'z'        => $z,
    'z²'       => $z ** 2,
    'distance' => abs($z),
];

fn(array $pair): int with ([$a, $b] = $pair, $sum = $a + $b) => $sum;
```

## Semantics

- The `with (...)` clause defines **local variables** visible only inside the arrow function
- Bindings are evaluated **left to right** at **invocation time**
- Each binding can refer to parameters and any previously defined binding
- Variable names cannot shadow parameters (error)
- Shadowing outer variables is allowed
- `&` references are not permitted inside `with (...)`
- Bindings are expressions; statements and control structures are prohibited
- Evaluation is eager

## Desugaring (Conceptual)

```php
fn(stdClass $product): bool with ($price = $product->price * 1.2) => $price > 4 && $price < 10;
```

Desugars logically to:

```php
fn(stdClass $product): bool => (function() use ($product) {
    $price = $product->price * 1.2;
    return $price > 4 && $price < 10;
})();
```

The engine would internally treat this as a blockified arrow function with initializer statements followed by an implicit `return` of the arrow expression. Note: The IIFE illustration above is only a conceptual device, not implementation guidance.

## Grammar

```
arrow_function:
    'static'? 'fn' '(' parameter_list? ')' return_type?
    with_clause? '=>' expr

with_clause:
    'with' '(' binding_list? ')'

binding_list:
    binding (',' binding)* (',' )?

binding:
    variable '=' expr
  | list_pattern '=' expr
```

The new keyword `with` is reserved in this context.

## Examples in Practice

### Cleaner mapping

Avoid duplicate work when building structured results.

```php
array_map(
    fn(User $u): array
        with (
            $full = trim("{$u->first} {$u->last}"),
            $initials = strtoupper($u->first[0] . $u->last[0]),
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $full))
        )
        => ['full' => $full, 'initials' => $initials, 'slug' => $slug],
    $users
);
```

### Composed helpers

```php
fn($xs) with (
  $head = fn($a) => $a[0] ?? null,
  $tail = fn($a) => array_slice($a, 1),
  $h = $head($xs),
  $t = $tail($xs)
) => [$h, $t];
```

### Conditional shorthand

```php
fn(int $n) with ($even = $n % 2 === 0) => $even ? 'even' : 'odd';
```

### Dependent bindings

Sequential bindings allow later values to depend on earlier ones:

```php
$squareandcube = fn(int $n): array with ($square = $n ** 2, $cube = $square * $n) => [$square, $cube];

array_map($squareandcube, [2, 4, 6]); //[[4, 8], [16, 64], [36, 216]]
```

This behaves like Haskell’s `where`:

```haskell
squareAndCube n = [square, cube]
  where
    square = n ^ 2
    cube = square * n
```

Except with PHP’s eager semantics and without nested scopes. These semantics also mirror Scheme's `let*` (sequential, dependent bindings), not parallel `let`.

## Real-world Use Cases

Beyond the small examples above, the following snippets show how `with (...)` improves common application-level patterns without abandoning arrow functions.

### Removing duplication in derived values (API resource transformer)

Transformers and JSON response builders frequently derive multiple fields from a shared intermediate value. Without `with`, either the expression must be duplicated or assignments must be embedded inside the returned array.

**Current code (without `with (...)`)**

```php
$toApi = fn(User $user): array => [
    'id'    => $user->id,
    'name'  => trim($user->first . ' ' . $user->last),
    'slug'  => strtolower(preg_replace(
        '/[^a-z0-9]+/',
        '-',
        trim($user->first . ' ' . $user->last)
    )),
    'active' => ! $user->deleted_at && $user->email_verified_at !== null,
];
```

The user’s full name is computed twice, and intermediate logic is embedded directly inside the return expression.

**With `with (...)`**

```php
$toApi = fn(User $user): array with (
    $name   = trim($user->first . ' ' . $user->last),
    $slug   = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name)),
    $active = ! $user->deleted_at && $user->email_verified_at !== null,
) => [
    'id'     => $user->id,
    'name'   => $name,
    'slug'   => $slug,
    'active' => $active,
];
```

This version eliminates duplication and makes derived values explicit, without requiring a multi-line closure body.

### Avoiding assignment tricks inside expressions (input normalization)

Normalizing and validating incoming data often requires a sequence of small steps. In arrow functions today, developers frequently resort to assignment inside the return array to avoid recomputing values.

**Current code (without `with (...)`)**

```php
$normalize = fn(array $data): array => [
    'email'    => $email = strtolower(trim($data['email'] ?? '')),
    'valid'    => $email && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
    'domain'   => $email ? substr(strrchr($email, '@') ?: '', 1) : null,
];
```

The assignment of `$email` inside the array literal is a common workaround, but it obscures intent and relies on a side-effect occurring within the expression.

**With `with (...)`**

```php
$normalize = fn(array $data): array with (
    $email  = strtolower(trim($data['email'] ?? '')),
    $valid  = $email && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
    $domain = $email ? substr(strrchr($email, '@') ?: '', 1) : null,
) => [
    'email'  => $email,
    'valid'  => $valid,
    'domain' => $domain,
];
```

This approach is clearer, eliminates side-effect assignments, and provides a more predictable structure.

### Eliminating repeated expensive or verbose expressions (database row → view model)

Transforming database rows into “view models” frequently requires normalizing text, computing excerpts, and deriving multiple values from the same base expression.

**Current code (without `with (...)`)**

```php
$toViewModel = fn(array $row): array => [
    'id'      => (int)$row['id'],
    'title'   => $row['title'],
    'excerpt' => mb_substr(strip_tags($row['body']), 0, 120) . '...',
    'reading' => max(
        1,
        (int)ceil(str_word_count(strip_tags($row['body'])) / 200)
    ),
];
```

The `strip_tags($row['body'])` call is repeated, and the overall transformation mixes concerns.

**With `with (...)`**

```php
$toViewModel = fn(array $row): array with (
    $id      = (int)$row['id'],
    $body    = strip_tags($row['body']),
    $excerpt = mb_substr($body, 0, 120) . '...',
    $reading = max(1, (int)ceil(str_word_count($body) / 200)),
) => [
    'id'      => $id,
    'title'   => $row['title'],
    'excerpt' => $excerpt,
    'reading' => $reading,
];
```

This separates the staging of intermediate values from the structure of the returned array, improving clarity while avoiding redundant computation.

### Multi-step calculations without expanding into a full closure (pricing example)

Arrow functions today cannot contain multiple statements, resulting in dense or duplicated computations for simple multi-step calculations.

**Current code (without `with (...)`)**

```php
$pricing = fn(Item $i): array => [
    'base'     => $i->price,
    'discount' => $i->discount_rate * $i->price,
    'tax'      => ($i->price - $i->discount_rate * $i->price) * 0.1,
    'final'    => round(
        ($i->price - $i->discount_rate * $i->price) * 1.1,
        2
    ),
];
```

The expression `$i->price - $i->discount_rate * $i->price` is duplicated, and the overall structure becomes harder to follow as additional fields are added.

**With `with (...)`**

```php
$pricing = fn(Item $i): array with (
    $base     = $i->price,
    $discount = $i->discount_rate * $base,
    $net      = $base - $discount,
    $tax      = $net * 0.1,
    $final    = round($net * 1.1, 2),
) => [
    'base'     => $base,
    'discount' => $discount,
    'tax'      => $tax,
    'final'    => $final,
];
```

Intermediate values become explicit, repeated expressions are eliminated, and the arrow function remains concise and expression-oriented.

## Design Intent and Non-Goals

This feature is **inspired by** Haskell's `where` but **deliberately simpler**:

| Feature                        | Haskell | PHP Proposal      |
|--------------------------------|---------|-------------------|
| Defines functions & values     | ✅      | ❌ values only    |
| Recursive / mutually recursive | ✅      | ❌                |
| Lazy                           | ✅      | ❌ eager          |
| Nested `where`                 | ✅      | ❌ not applicable |

**Non-Goals**

- No recursion or hoisting between bindings.
- No statements, loops, or conditionals in `with (...)`.
- No multi-level nesting of `with` clauses.
- Not a replacement for blocks or full closures.

This keeps the feature small, predictable, and consistent with PHP’s expression only arrow functions.

## Rationale for the `with` Keyword

The primary inspiration for this feature is Haskell’s `where` clause, which attaches local bindings to a function definition:

```haskell
squareAndCube n = [square, cube]
  where
    square = n ^ 2
    cube   = square * n
```

However, the placement and naming in PHP differ deliberately:

- In Haskell, `where` appears **after** the equation; in PHP arrows, the clause appears **after** the parameter list and before the body: `fn(...) with (...) => ...`. This reads naturally as “a function **with** these bindings”.
- `with` avoids overloading the word `where`, which is already used for constraints in Hack generics and is a strong candidate for any future PHP generic constraint syntax.
- `with` lines up conceptually with SQL’s `WITH` common table expressions (CTEs), which name intermediate results for the rest of a query.
- In the PHP ecosystem, `with` is already familiar in helpers like Laravel’s `with()` and other “scope functions” in languages such as Kotlin’s `with` and `let`, which exist to introduce short‑lived names around an expression.

Taken together, this makes `with`:

- Easy to read in code: `fn($x) with ($double = $x * 2) => $double`.
- Familiar to developers coming from SQL, Haskell, and modern functional style.
- Safer for PHP’s long‑term grammar than reusing `where`.

## Implementation Notes

* **AST**: add an optional `with_bindings` property to the `ast_arrow_function` node.
* **Parser**: allow an optional `T_WITH '(' ... ')'` between parameter list and `T_DOUBLE_ARROW`.
* **Compiler**: emit opcodes that assign each binding before evaluating the arrow body.
* **Engine behavior**: identical to current arrow functions, with local variables initialised beforehand.
* **Reflection**: expose bindings as part of the AST node (for tooling), e.g. via a `ReflectionFunction::getWithBindings()` helper.

## Implementation

A prototype implementation of `with (...)` bindings for arrow functions is available for experimentation in php-src at:

> https://github.com/cameron1729/php-src/tree/with-syntax

This branch implements the parser, AST, and engine changes described above.
The text of this RFC and example scripts (including `demo.php`) are available in the companion repository:

> https://github.com/cameron1729/php-with-rfc

The `demo.php` script can be executed using a PHP binary built from the `with-syntax` prototype branch.

## Alternative: Overloading `use`

One considered alternative was to overload `use (...)` on arrow functions to carry bindings, e.g. `fn(...) use ($x = expr) => ...`.

Pros

- Reuses an existing keyword and familiar syntax; no new reserved word.

Cons

- Semantic inversion: `use` historically means "capture from outer scope" whereas here we want "define locals" - different concepts sharing syntax.
- Grammar and readability: `use` currently accepts only variable names (and references) on closures; allowing full assignment expressions would create a special case just for arrows.
- Mental model: arrows already implicitly capture; adding a second, different meaning for `use` on arrows conflicts with that design and increases confusion (`use($a)` vs `use($a = ...)`).
- Future evolution: keeping `use`'s meaning consistent across closures/arrows preserves space for potential extensions without ambiguity.

A dedicated `with (...)` clause makes intent explicit, keeps local initialisation clearly separate, and avoids overloading `use` with two meanings.


## Relationship to `use`

`use` and `with` serve complementary roles:

- `use (...)` declares external dependencies; i.e., variables captured from the surrounding scope.
- `with (...)` declares internal derivations; i.e., local variables computed inside the function from parameters (and prior bindings).

Keeping these concerns distinct improves readability: the signature shows parameters; `use` communicates "what this function needs from outside"; `with` communicates "what this function derives before evaluating the body”. They are orthogonal features and can conceptually coexist (e.g., a future extension for block closures: `function (...) use ($dep) with ($tmp = expr) { ... }`).

## Backward Compatibility

- `with` will become a **reserved keyword**.
- No changes to existing arrow or closure semantics.
- Minimal parser impact: the new production is unambiguous.

## Performance Impact

Negligible. Bindings are compiled to sequential assignment opcodes preceding the return expression.

## Relationship to Other PHP Expression Features

### Pipe Operator (`|>`)

The proposed `with (...)` clause is orthogonal to the `|>` operator. The pipe structures the sequence of computations between expressions; `with` provides clear, named locals inside a single arrow step. Used together, they enable a clean, functional pipeline style without sacrificing PHP's readability.

Example (combining both):

```php
$data |> fn($v) with (
    $sum   = array_sum($v),
    $mean  = $sum / count($v),
    $sq    = array_map(fn($x) => ($x - $mean) ** 2, $v),
    $sd    = sqrt(array_sum($sq) / count($v))
) => ['mean' => $mean, 'sd' => $sd];
```

Conceptually:

- `|>` improves flow between steps; it passes the left value as the first argument to the right expression.
- `with` improves clarity within a step; it names intermediate values used by the arrow's body.

This complements PHP's multi-paradigm nature. The pipe operator offers a functional sequencing idiom, while `with` offers local naming ideas inspired by languages like Haskell and SQL `WITH` clauses, adapted to PHP's eager semantics and single‑expression arrows.

### Function Composition

The Function Composition RFC proposes a first‑class composition operator to build functions from functions (https://wiki.php.net/rfc/function-composition). Composition remains orthogonal to `with`:

- Composition glues reusable steps together (reusability and abstraction).
- `with` clarifies the internals of a single step (local names, dependent bindings).

Used together with `|>`, pipelines can be written as composed stages, each with a clear internal story via `with`.

### Partial Function Application

The Partial Function Application v2 RFC (https://wiki.php.net/rfc/partial_function_application_v2) continues PHP’s trend toward more expressive, functional-style building blocks at the expression level. It allows developers to write compact, reusable functions by fixing some arguments ahead of time.

`with` complements partial application:

- Partial application is about **reusing** functions by pre-filling arguments.
- `with` is about **clarifying internals** of a single arrow invocation by naming intermediate values.

Together with `|>` and function composition, partials and `with` give PHP a coherent set of tools for functional pipelines: partials and composition define the steps, `|>` wires them together, and `with` keeps each step readable by naming its internal calculations.

## Relationship to Other Languages and Features

Many languages offer local binding constructs that improve readability of small transformations:
- Haskell: `where` and `let/in` establish local names; often lazy and may permit recursive groups.
- ML family (OCaml/F#): `let` bindings in expressions; eager evaluation, typically non‑recursive by default.
- Scheme: `let` (parallel) vs `let*` (sequential, dependent); the `with` clause mirrors `let*` semantics.
- C# LINQ: `let` introduces computed names within query comprehensions.
- SQL: `WITH` (CTE) names intermediate results at statement scope.

In parallel, PHP's current and proposed features draw on functional idioms:
- Pipelines: `|>` enables left‑to‑right function application (cf. Elixir, F#, Hack).
- Composition: the Function Composition RFC proposes building functions from functions.
- Local bindings: `with` names intermediates within a single step.

These are orthogonal and complementary: `|>` expresses flow between steps; composition builds reusable steps; `with` clarifies internals within a step.

## Future Scope

- Potential extension to normal closures (`function (...) use (...) with (...) { ... }`) if desired
- Static analysis tools and IDEs can infer types for bound locals

## Voting

Simple Yes/No vote: Add `with (...)` bindings to arrow functions.

## References

1. **Haskell 2010 Language Report**
   Simon Marlow (ed.), Cambridge University Press, 2010.
   Chapter 4: *Declarations and Bindings* — includes function and pattern bindings, `let`, and `where` constructs.
   [https://www.haskell.org/onlinereport/haskell2010/](https://www.haskell.org/onlinereport/haskell2010/)
   [https://www.haskell.org/onlinereport/haskell2010/haskellch4.html](https://www.haskell.org/onlinereport/haskell2010/haskellch4.html)

2. **PHP RFC: Arrow Functions 2.0 (Short Closures)**
   Nikita Popov, 2019.
   Defines the `fn` arrow syntax and captures semantics, serving as the baseline for this proposal.
   [https://wiki.php.net/rfc/arrow_functions_v2](https://wiki.php.net/rfc/arrow_functions_v2)

3. **PHP Manual — Arrow Functions**
   Official documentation for short closures, including `fn` semantics and `use` capture behavior.
   [https://www.php.net/manual/en/functions.arrow.php](https://www.php.net/manual/en/functions.arrow.php)

4. **PHP RFC: Pipe Operator v3**
   Proposal for a `|>` operator that structures pipelines of expression-level transformations.
   [https://wiki.php.net/rfc/pipe-operator-v3](https://wiki.php.net/rfc/pipe-operator-v3)

5. **PHP RFC: Function Composition**
   Proposal to introduce a function composition operator, complementing `|>` and `with`.
   [https://wiki.php.net/rfc/function-composition](https://wiki.php.net/rfc/function-composition)

6. **PHP RFC: Partial Function Application v2**
   Introduces partial application and placeholders for function arguments, further expanding PHP's functional-style expression tools.
   [https://wiki.php.net/rfc/partial_function_application_v2](https://wiki.php.net/rfc/partial_function_application_v2)

7. **F# Language Reference — `let` and `where` bindings**
   Shows similar semantics for local bindings in functional expressions, where `let` defines locals in expression scope.
   [https://learn.microsoft.com/en-us/dotnet/fsharp/language-reference/functions/let-bindings](https://learn.microsoft.com/en-us/dotnet/fsharp/language-reference/functions/let-bindings)

8. **OCaml Manual — Local Bindings with `let`**
   Describes eager local binding semantics in a strict functional language, close to PHP's evaluation model.
   [https://ocaml.org/manual/expr.html#let-expressions](https://ocaml.org/manual/expr.html#let-expressions)

9. **Scala Language Specification**
   Section 6.18: *Block Expressions and Local Definitions* — demonstrates local definitions inside expressions.
   [https://scala-lang.org/files/archive/spec/2.13/06-expressions.html](https://scala-lang.org/files/archive/spec/2.13/06-expressions.html)

10. **Kotlin Language Reference — `let` Scope Function**
   Illustrates local expression‑scoped bindings within lambda expressions, similar in purpose to `with`.
   [https://kotlinlang.org/docs/scope-functions.html#let](https://kotlinlang.org/docs/scope-functions.html#let)

11. **Swift Language Guide — Local Constants and Variables**
    Demonstrates expression‑local binding within closures and block scopes; a parallel to `with` semantics.
    [https://docs.swift.org/swift-book/LanguageGuide/TheBasics.html#ID326](https://docs.swift.org/swift-book/LanguageGuide/TheBasics.html#ID326)

12. **SQL Common Table Expressions (CTEs)**
    Engine-agnostic overview of SQL `WITH` clauses used to name intermediate query results, conceptually similar to PHP's `with` bindings for expression-local intermediates.
    [https://en.wikipedia.org/wiki/Common_table_expression](https://en.wikipedia.org/wiki/Common_table_expression)

These references collectively show that expression‑local bindings are a recurring idea across functional and multi-paradigm languages. Haskell's `where` is the conceptual origin; SQL's `WITH` clauses provide a statement-level analogue; PHP's `with` clause adapts these ideas to arrows; and modern languages like F#, Scala, Kotlin, and Swift illustrate pragmatic adaptations of the same idea in eager, expression-centric contexts.
