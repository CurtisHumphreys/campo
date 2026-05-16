<?php

interface ChurchSuiteClientInterface {
    public function getStatus();

    public function searchEvents($query = '', $dateStart = null, $dateEnd = null, $page = 1, $perPage = 25);

    public function getEvent($reference);

    public function listSignups($eventId, $page = 1, $perPage = 100, $status = null);

    public function listTickets($eventId, $page = 1, $perPage = 100);

    public function listContacts($page = 1, $perPage = 100);

    public function listChildren($page = 1, $perPage = 100);

    public function listParentCarerRelationships($page = 1, $perPage = 100);
}
