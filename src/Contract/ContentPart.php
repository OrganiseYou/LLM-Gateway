<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * One part of a message's content. A marker: test a part with instanceof and skip a part class you do not know,
 * because a later version adds part classes.
 */
interface ContentPart {}
