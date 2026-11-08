<?php
declare(strict_types=1);

namespace StretchEffect\Mcp;

/** A tool call failed in a way the model can fix. The message is safe to show (no values, no SQL, no paths). */
final class ToolInputError extends \RuntimeException {}
