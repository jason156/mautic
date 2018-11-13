<?php

/*
 * @package     Mautic
 * @copyright   2018 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\Migrations;

use Doctrine\DBAL\Migrations\SkipMigrationException;
use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Doctrine\AbstractMauticMigration;

class Version20181106091943 extends AbstractMauticMigration
{
    /**
     * @param Schema $schema
     *
     * @throws SkipMigrationException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    public function preUp(Schema $schema)
    {
        if ($schema->getTable("{$this->prefix}email_stats")->hasColumn("{$this->prefix}generated_sent_date")) {
            throw new SkipMigrationException('Schema includes this migration');
        }
    }

    /**
     * @param Schema $schema
     */
    public function up(Schema $schema)
    {
        $this->addSql("ALTER TABLE {$this->prefix}email_stats ADD generated_sent_date DATE AS (CONCAT(YEAR(date_sent), '-', LPAD(MONTH(date_sent), 2, '0'), '-', LPAD(DAY(date_sent), 2, '0'))) COMMENT '(DC2Type:generated)'");
        $this->addSql("ALTER TABLE {$this->prefix}email_stats ADD index `{$this->prefix}email_id_date_string`(email_id,generated_sent_date)");
    }
}
