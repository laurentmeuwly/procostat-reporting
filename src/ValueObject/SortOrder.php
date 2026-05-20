<?php

namespace Procorad\ProcostatReporting\ValueObject;

enum SortOrder
{
    case LABEL_ASC;   // ordre croissant des numéro d'anonymat des labos (défaut)
    case VALUE_ASC;   // croissant par valeur
    case VALUE_DESC;  // décroissant par valeur
}
