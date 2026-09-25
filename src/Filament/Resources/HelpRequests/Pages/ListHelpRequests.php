<?php

namespace Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages;

use Filament\Resources\Pages\ListRecords;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\HelpRequestResource;

class ListHelpRequests extends ListRecords
{
    protected static string $resource = HelpRequestResource::class;
}
