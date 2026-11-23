<?php
/**
 * Word lists for ANIMAL-COLOUR codes. Easy to read and spell, no look-alikes.
 * 60 animals x 16 colours = 960 combinations, so a random guess has about a 3% chance
 * of hitting one of 30 codes. The wrong-code limit in rest.php stops repeated guessing.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

if (!defined('ABSPATH')) {
	exit;
}

const ANIMALS = [
	'BADGER', 'BEAR', 'BEAVER', 'BISON', 'CAMEL', 'CAT', 'CHEETAH', 'COW', 'CRAB', 'CROW',
	'DEER', 'DOG', 'DOLPHIN', 'DONKEY', 'DUCK', 'EAGLE', 'ELEPHANT', 'FALCON', 'FOX', 'FROG',
	'GIRAFFE', 'GOAT', 'GOOSE', 'GORILLA', 'HAMSTER', 'HARE', 'HAWK', 'HEDGEHOG', 'HIPPO', 'HORSE',
	'KANGAROO', 'KOALA', 'LION', 'LIZARD', 'LLAMA', 'MONKEY', 'MOOSE', 'MOUSE', 'OTTER', 'OWL',
	'PANDA', 'PARROT', 'PENGUIN', 'PIG', 'PUFFIN', 'RABBIT', 'RAVEN', 'SEAL', 'SHARK', 'SHEEP',
	'SNAIL', 'SPIDER', 'SQUIRREL', 'SWAN', 'TIGER', 'TORTOISE', 'TURTLE', 'WHALE', 'WOLF', 'ZEBRA',
];

const COLOURS = [
	'RED', 'BLUE', 'GREEN', 'YELLOW', 'ORANGE', 'PURPLE', 'PINK', 'BLACK',
	'WHITE', 'GREY', 'BROWN', 'GOLD', 'SILVER', 'TEAL', 'NAVY', 'LIME',
];
