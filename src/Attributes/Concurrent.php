<?php

namespace JordanPartridge\ParallelTools\Attributes;

use Attribute;

/**
 * Run this tool beside other marked calls from the same model step.
 *
 * Unmarked tools stay sequential and split the step. The process driver
 * serializes the tool, so it and its result must be serializable.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Concurrent
{
    //
}
