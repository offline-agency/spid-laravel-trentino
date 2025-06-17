<?php

namespace OfflineAgency\SpidLaravelTrentino;

class SpidTrentinoUser
{
    private $sub;
    private $zoneinfo;
    private $entiIssuersource;
    private $preferredUsername;
    private $locale;
    private $givenName;
    private $entiAcr;
    private $entiSpid;
    private $realm;
    private $entiCodicefiscale;
    private $id;
    private $familyName;

    // Getters and Setters

    public function getSub()
    {
        return $this->sub;
    }

    public function setSub($sub)
    {
        $this->sub = $sub;
    }

    public function getZoneinfo()
    {
        return $this->zoneinfo;
    }

    public function setZoneinfo($zoneinfo)
    {
        $this->zoneinfo = $zoneinfo;
    }

    public function getEntiIssuersource()
    {
        return $this->entiIssuersource;
    }

    public function setEntiIssuersource($entiIssuersource)
    {
        $this->entiIssuersource = $entiIssuersource;
    }

    public function getPreferredUsername()
    {
        return $this->preferredUsername;
    }

    public function setPreferredUsername($preferredUsername)
    {
        $this->preferredUsername = $preferredUsername;
    }

    public function getLocale()
    {
        return $this->locale;
    }

    public function setLocale($locale)
    {
        $this->locale = $locale;
    }

    public function getGivenName()
    {
        return $this->givenName;
    }

    public function setGivenName($givenName)
    {
        $this->givenName = $givenName;
    }

    public function getEntiAcr()
    {
        return $this->entiAcr;
    }

    public function setEntiAcr($entiAcr)
    {
        $this->entiAcr = $entiAcr;
    }

    public function getEntiSpid()
    {
        return $this->entiSpid;
    }

    public function setEntiSpid($entiSpid)
    {
        $this->entiSpid = $entiSpid;
    }

    public function getRealm()
    {
        return $this->realm;
    }

    public function setRealm($realm)
    {
        $this->realm = $realm;
    }

    public function getEntiCodicefiscale()
    {
        return $this->entiCodicefiscale;
    }

    public function setEntiCodicefiscale($entiCodicefiscale)
    {
        $this->entiCodicefiscale = $entiCodicefiscale;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getFamilyName()
    {
        return $this->familyName;
    }

    public function setFamilyName($familyName)
    {
        $this->familyName = $familyName;
    }

    // toArray Method to Convert Object to Array
    public function toArray()
    {
        return [
            'sub' => $this->getSub(),
            'zoneinfo' => $this->getZoneinfo(),
            'enti-issuersource' => $this->getEntiIssuersource(),
            'preferred_username' => $this->getPreferredUsername(),
            'locale' => $this->getLocale(),
            'given_name' => $this->getGivenName(),
            'enti-acr' => $this->getEntiAcr(),
            'enti-spid' => $this->getEntiSpid(),
            'realm' => $this->getRealm(),
            'enti-codicefiscale' => $this->getEntiCodicefiscale(),
            'id' => $this->getId(),
            'family_name' => $this->getFamilyName(),
        ];
    }

    // Method to Get Fiscal Number (fiscalCode)
    public function getFiscalNumber()
    {
        return isset($this->entiCodicefiscale['fiscalCode']) ? $this->entiCodicefiscale['fiscalCode'] : null;
    }

    // Method to Get Full Name
    public function getName()
    {
        return $this->getGivenName(); // Return only the given name
    }

    // Method to Get Surname (Family Name)
    public function getSurname()
    {
        return $this->getFamilyName(); // Return only the family name
    }
}
