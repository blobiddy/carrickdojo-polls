<?php
/**
 * Word lists. Easy to read and spell, no look-alikes.
 *
 * Codes are COLOUR-ANIMAL: 16 x 60 = 960 combinations, so a random guess has about a 3% chance
 * of hitting one of 30 codes. The wrong-code limit stops repeated guessing.
 *
 * Quiz nicknames are Adjective Animal (e.g. "Brave Otter"). They deliberately use different
 * words from codes, so a nickname on a leaderboard never gives away anyone's code.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

if (!defined('ABSPATH')) {
	exit;
}

const COLOURS = [
	'RED', 'BLUE', 'GREEN', 'YELLOW', 'ORANGE', 'PURPLE', 'PINK', 'BLACK',
	'WHITE', 'GREY', 'BROWN', 'GOLD', 'SILVER', 'TEAL', 'NAVY', 'LIME',
];

const ANIMALS = [
	'BADGER', 'BEAR', 'BEAVER', 'BISON', 'CAMEL', 'CAT', 'CHEETAH', 'COW', 'CRAB', 'CROW',
	'DEER', 'DOG', 'DOLPHIN', 'DONKEY', 'DUCK', 'EAGLE', 'ELEPHANT', 'FALCON', 'FOX', 'FROG',
	'GIRAFFE', 'GOAT', 'GOOSE', 'GORILLA', 'HAMSTER', 'HARE', 'HAWK', 'HEDGEHOG', 'HIPPO', 'HORSE',
	'KANGAROO', 'KOALA', 'LION', 'LIZARD', 'LLAMA', 'MONKEY', 'MOOSE', 'MOUSE', 'OTTER', 'OWL',
	'PANDA', 'PARROT', 'PENGUIN', 'PIG', 'PUFFIN', 'RABBIT', 'RAVEN', 'SEAL', 'SHARK', 'SHEEP',
	'SNAIL', 'SPIDER', 'SQUIRREL', 'SWAN', 'TIGER', 'TORTOISE', 'TURTLE', 'WHALE', 'WOLF', 'ZEBRA',
];

const NICKNAME_ADJECTIVES = [
	'Brave', 'Bright', 'Bouncy', 'Busy', 'Calm', 'Clever', 'Cosmic', 'Curious', 'Daring', 'Dizzy',
	'Eager', 'Fancy', 'Fearless', 'Friendly', 'Fuzzy', 'Gentle', 'Giant', 'Happy', 'Jolly', 'Lucky',
	'Mighty', 'Nimble', 'Plucky', 'Quick', 'Quiet', 'Rapid', 'Sleepy', 'Sneaky', 'Speedy', 'Sunny',
	'Super', 'Swift', 'Tiny', 'Witty', 'Zany', 'Zippy',
];
