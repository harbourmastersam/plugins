<?php

namespace GreyHarbour\DatabaseViewer\Enums;

enum AllowedQuery: string
{
    case Diagnostic = 'diagnostic';
    case CurrentDatabase = 'current_database';
    case Schema = 'schema';
    case Tables = 'tables';
    case Columns = 'columns';
    case Constraints = 'constraints';
    case ConstraintColumns = 'constraint_columns';
    case Triggers = 'triggers';
}
