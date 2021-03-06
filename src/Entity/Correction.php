<?php

/*
 * Copyright Daniel Berthereau 2019-2020
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace Correction\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Resource;

/**
 * @Entity
 * @Table(
 *     indexes={
 *         @Index(
 *             name="correction_email_idx",
 *             columns={"email"}
 *         ),
 *         @Index(
 *             name="correction_modified_idx",
 *             columns={"modified"}
 *         )
 *     }
 * )
 */
class Correction extends AbstractEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * Corrections are not annotations in this module, so they are deleted.
     *
     * @var \Omeka\Entity\Resource
     * @ManyToOne(
     *     targetEntity="\Omeka\Entity\Resource"
     * )
     * @JoinColumn(
     *     nullable=false,
     *     onDelete="CASCADE"
     * )
     */
    protected $resource;

    /**
     * @todo Allow to keep history of all corrections (ManyToOne)?
     *
     * @var \Correction\Entity\Token
     * @OneToOne(
     *     targetEntity="Token"
     * )
     * @JoinColumn(
     *     nullable=true,
     *     onDelete="SET NULL"
     * )
     */
    protected $token;

    /**
     * The email is saved in the case the token is deleted, so it will be still
     * possible to get the propositions of a user.
     *
     * @var string
     * @Column(
     *     type="string",
     *     length=190,
     *     nullable=true
     * )
     */
    protected $email;

    /**
     * @var bool
     * @Column(
     *     type="boolean",
     *     nullable=false
     * )
     */
    protected $reviewed = false;

    /**
     * @var array
     * @Column(
     *     type="json_array"
     * )
     */
    protected $proposal;

    /**
     * @var DateTime
     * @Column(
     *     type="datetime"
     * )
     */
    protected $created;

    /**
     * @var DateTime
     * @Column(
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $modified;

    public function getId()
    {
        return $this->id;
    }

    /**
     * @param Resource $resource
     * @return self
     */
    public function setResource(Resource $resource)
    {
        $this->resource = $resource;
        return $this;
    }

    /**
     * @return \Omeka\Entity\Resource
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @param Token|null $token
     * @return self
     */
    public function setToken(Token $token = null)
    {
        $this->token = $token;
        return $this;
    }

    /**
     * @return \Correction\Entity\Token
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * @param string|null $email
     * @return \Correction\Entity\Correction
     */
    public function setEmail($email = null)
    {
        $this->email = $email;
        return $this;
    }

    /**
     * @return string
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * @param bool $reviewed
     * @return self
     */
    public function setReviewed($reviewed)
    {
        $this->reviewed = (bool) $reviewed;
        return $this;
    }

    /**
     * @return bool
     */
    public function getReviewed()
    {
        return (bool) $this->reviewed;
    }

    /**
     * @param array $proposal
     * @return self
     */
    public function setProposal(array $proposal)
    {
        $this->proposal = $proposal;
        return $this;
    }

    /**
     * @return array
     */
    public function getProposal()
    {
        return $this->proposal;
    }

    /**
     * @param DateTime $dateTime
     * @return self
     */
    public function setCreated(DateTime $dateTime)
    {
        $this->created = $dateTime;
        return $this;
    }

    /**
     * @return DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * @param DateTime|null $dateTime
     * @return self
     */
    public function setModified(DateTime $dateTime = null)
    {
        $this->modified = $dateTime;
        return $this;
    }

    /**
     * @return DateTime|null
     */
    public function getModified()
    {
        return $this->modified;
    }
}
