<?php
/**
 * Word lists for codes. Easy to read and spell, no look-alikes.
 *
 * Member codes are COLOUR-ANIMAL: 16 x 60 = 960 combinations, so a random guess has about a 3% chance
 * of hitting one of 30 codes. The wrong-code limit stops repeated guessing.
 * Mentor and screen codes unlock more, so they are four words (COLOUR-ANIMAL-COLOUR-ANIMAL):
 * about 920,000 combinations.
 *
 * Personas use their own word lists, kept on the club site (src/content/personas.md).
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
