<?php

namespace Materom\Vigil;

enum QueryConnectionType: string
{
    case Read = 'read';
    case Write = 'write';
    case Direct = 'direct';
    case Unknown = 'unknown';
}
