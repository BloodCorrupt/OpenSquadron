<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=opensquadron', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sql1 = "CREATE TABLE IF NOT EXISTS broadcast_campaigns (
        id INT AUTO_INCREMENT NOT NULL, 
        owner_id INT NOT NULL, 
        connection_id INT NOT NULL, 
        campaign_name VARCHAR(255) NOT NULL, 
        broadcast_type VARCHAR(50) NOT NULL, 
        template_name VARCHAR(255) DEFAULT NULL, 
        audience_filters LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)', 
        assign_label_after LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)', 
        scheduled_at VARCHAR(50) DEFAULT NULL, 
        status VARCHAR(50) NOT NULL, 
        processed_count INT DEFAULT 0 NOT NULL, 
        delivered_count INT DEFAULT 0 NOT NULL, 
        opened_count INT DEFAULT 0 NOT NULL, 
        unreached_count INT DEFAULT 0 NOT NULL, 
        INDEX IDX_B22BC48D7E3C61F9 (owner_id), 
        INDEX IDX_B22BC48D80F174B0 (connection_id), 
        PRIMARY KEY(id)
    ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB";
    
    $sql1_constraints = "ALTER TABLE broadcast_campaigns ADD CONSTRAINT FK_B22BC48D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id);
    ALTER TABLE broadcast_campaigns ADD CONSTRAINT FK_B22BC48D80F174B0 FOREIGN KEY (connection_id) REFERENCES facebook_connection (id) ON DELETE CASCADE;";
    
    $sql2 = "CREATE TABLE IF NOT EXISTS web_widgets (
        id INT AUTO_INCREMENT NOT NULL, 
        owner_id INT NOT NULL, 
        connection_id INT NOT NULL, 
        widget_name VARCHAR(255) NOT NULL, 
        widget_type VARCHAR(50) NOT NULL, 
        customization LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)', 
        INDEX IDX_E282714D7E3C61F9 (owner_id), 
        INDEX IDX_E282714D80F174B0 (connection_id), 
        PRIMARY KEY(id)
    ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB";
    
    $sql2_constraints = "ALTER TABLE web_widgets ADD CONSTRAINT FK_E282714D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id);
    ALTER TABLE web_widgets ADD CONSTRAINT FK_E282714D80F174B0 FOREIGN KEY (connection_id) REFERENCES facebook_connection (id) ON DELETE CASCADE;";
    
    $pdo->exec($sql1);
    try { $pdo->exec($sql1_constraints); } catch (Exception $e) {}
    $pdo->exec($sql2);
    try { $pdo->exec($sql2_constraints); } catch (Exception $e) {}
    
    echo 'SUCCESS';
} catch (Exception $e) {
    echo $e->getMessage();
}
