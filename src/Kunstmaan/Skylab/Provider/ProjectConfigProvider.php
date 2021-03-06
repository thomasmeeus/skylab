<?php

namespace Kunstmaan\Skylab\Provider;

use Cilex\Application;
use Kunstmaan\Skylab\Entity\PermissionDefinition;
use Symfony\Component\Finder\Finder;

/**
 * ProjectConfigProvider
 */
class ProjectConfigProvider extends AbstractProvider
{

    /**
     * Registers services on the given app.
     *
     * @param Application $app An Application instance
     */
    public function register(Application $app)
    {
        $app['projectconfig'] = $this;
        $this->app = $app;
    }

    /**
     * @param  string       $projectname The project name
     * @return \ArrayObject
     */
    public function loadProjectConfig($projectname)
    {
        $config = new \ArrayObject();
        $config = $this->loadConfig($projectname, $config);
        $config = $this->loadOwnership($projectname, $config);
        $config = $this->loadPermissions($projectname, $config);
        $config = $this->loadBackup($projectname, $config);

        return $config;
    }

    /**
     * @param $projectname
     * @param $config
     * @return \ArrayObject
     */
    private function loadConfig($projectname, \ArrayObject $config)
    {
        $configPath = $this->fileSystemProvider->getProjectConfigDirectory($projectname) . "/config.xml";
        $xml = simplexml_load_file($configPath);
        foreach ($xml->{'var'} as $var) {
            $tag = (string) $var["name"];
            switch ($tag) {
                case "project.skeletons":
                    foreach ($var->{'item'} as $skel) {
                        $config["skeletons"][(string) $skel["value"]] = (string) $skel["value"];
                    }
                    break;
                case "project.aliases":
                    foreach ($var->{'item'} as $alias) {
                        $config["aliases"][] = (string) $alias["value"];
                    }
                    break;
                default:
                    $config[str_replace("project.", "", $tag)] = (string) $var["value"];
            }
        }

        return $config;
    }

    /**
     * @param $projectname
     * @param  \ArrayObject $config
     * @return \ArrayObject
     */
    private function loadOwnership($projectname, \ArrayObject $config)
    {
        $configPath = $this->fileSystemProvider->getProjectConfigDirectory($projectname) . "/ownership.xml";
        $xml = simplexml_load_file($configPath);
        foreach ($xml->{'var'} as $var) {
            $name = (string) $var["name"];
            $value = (string) $var["value"];
            if (isset($config["permissions"][$name])) {
                $permissionDefinition = $config["permissions"][$name];
            } else {
                $permissionDefinition = new PermissionDefinition();
            }
            $permissionDefinition->setPath($name);
            $permissionDefinition->setOwnership($value);
            $config["permissions"][$name] = $permissionDefinition;
        }

        return $config;
    }

    /**
     * @param  string       $value
     * @param  \ArrayObject $config
     * @return string
     */
    public function searchReplacer($value, \ArrayObject $config)
    {
        $replaceDictionary = new \ArrayObject(array(
            "config.superuser" => $this->app["config"]["users"]["superuser"],
            "config.supergroup" => $this->app["config"]["users"]["supergroup"],
            "config.wwwuser" => $this->app["config"]["users"]["wwwuser"],
            "project.group" => $config["name"],
            "project.user" => $config["name"],
            "project.ip" => "*",
            "project.url" => $config["url"],
            "project.admin" => $this->app["config"]["apache"]["admin"],
            "project.dir" => $config["dir"],
            "config.projectsdir" => $this->app["config"]["projects"]["path"],
            "project.name" => $config["name"],
            "project.statsurl" => $config["statsurl"]
        ));

        preg_match_all("/@(\w*?\.\w*?)@/", $value, $hits);
        foreach ($hits[0] as $index => $hit) {
            $value = str_replace($hit, $replaceDictionary[$hits[1][$index]], $value);
        }

        return $value;
    }

    /**
     * @param $projectname
     * @param  \ArrayObject $config
     * @return \ArrayObject
     */
    private function loadPermissions($projectname, \ArrayObject $config)
    {
        $configPath = $this->fileSystemProvider->getProjectConfigDirectory($projectname) . "/permissions.xml";
        $xml = simplexml_load_file($configPath);
        foreach ($xml->{'var'} as $var) {
            $name = (string) $var["name"];
            if (isset($config["permissions"][$name])) {
                $permissionDefinition = $config["permissions"][$name];
            } else {
                $permissionDefinition = new PermissionDefinition();
            }
            $permissionDefinition->setPath($name);
            foreach ($var->{'item'} as $item) {
                $value = (string) $item["value"];
                $permissionDefinition->addAcl($value);
            }
            $config["permissions"][$name] = $permissionDefinition;
        }

        return $config;
    }

    /**
     * @param $projectname
     * @param  \ArrayObject $config
     * @return \ArrayObject
     */
    private function loadBackup($projectname, \ArrayObject $config)
    {
        $configPath = $this->fileSystemProvider->getProjectConfigDirectory($projectname) . "/backup.xml";
        $xml = simplexml_load_file($configPath);
        foreach ($xml->{'var'}[0]->item as $item) {
            $value = (string) $item["value"];
            $config["backupexcludes"][$value] = $value;
        }

        return $config;
    }

    /**
     * @param \ArrayObject $project The project
     */
    public function writeProjectConfig(\ArrayObject $project)
    {
        $this->dialogProvider->logTask("Writing configuration files");
        $this->writeConfig($project);
        $this->writeOwnership($project);
        $this->writePermissions($project);
        $this->writeBackup($project);
    }

    /**
     * @param \ArrayObject $project
     */
    private function writeConfig(\ArrayObject $project)
    {
        $configPath = $this->fileSystemProvider->getProjectConfigDirectory($project["name"]) . "/config.xml";
        $this->dialogProvider->logConfig("Writing the project config to " . $configPath);
        $config = new \SimpleXMLElement('<?xml version="1.0" ?><config></config>');
        /* @var $skeletonProvider SkeletonProvider */
        $skeletonProvider = $this->app['skeleton'];
        $skels = $config->addChild('var');
        $skels->addAttribute("name", "project.skeletons");
        foreach ($project["skeletons"] as $skeletonname) {
            $this->addItem($skels, $skeletonname);
            $skeleton = $skeletonProvider->findSkeleton($skeletonname);
            $config = $skeleton->writeConfig($project, $config);
        }
        $this->writeToFile($config, $configPath);
    }

    /**
     * @param  \SimpleXMLElement $node
     *                                  @param $name
     * @param  array             $items
     * @return \SimpleXMLElement
     *
     */
    public function addVarWithItems(\SimpleXMLElement $node, $name, array $items)
    {
        $var = $node->addChild('var');
        $var->addAttribute("name", $name);
        foreach ($items as $value) {
            $item = $var->addChild('item');
            $item->addAttribute("value", $value);
        }

        return $node;
    }

    /**
     * @param  \SimpleXMLElement $var
     * @param  string            $value
     * @return \SimpleXMLElement
     */
    public function addItem(\SimpleXMLElement $var, $value)
    {
        $item = $var->addChild('item');
        $item->addAttribute("value", $value);

        return $var;
    }

    /**
     * @param $xml
     * @param $path
     */
    private function writeToFile($xml, $path)
    {
        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $this->fileSystemProvider->writeProtectedFile($path, $dom->saveXML());
    }

    /**
     * @param \ArrayObject $project
     */
    private function writeOwnership(\ArrayObject $project)
    {
        $ownershipPath = $this->fileSystemProvider->getProjectConfigDirectory($project["name"]) . "/ownership.xml";
        $this->dialogProvider->logConfig("Writing the project's ownership config to " . $ownershipPath);
        $ownership = new \SimpleXMLElement('<?xml version="1.0" ?><config></config>');
        /** @var PermissionDefinition $permission */
        foreach ($project["permissions"] as $permission) {
            $ownership = $this->addVar($ownership, $permission->getPath(), $permission->getOwnership());
        }
        $this->writeToFile($ownership, $ownershipPath);
    }

    /**
     * @param  \SimpleXMLElement $node
     * @param  string            $name
     * @param  string            $value
     * @return \SimpleXMLElement
     */
    public function addVar(\SimpleXMLElement $node, $name, $value)
    {
        $var = $node->addChild('var');
        $var->addAttribute("name", $name);
        $var->addAttribute("value", $value);

        return $node;
    }

    /**
     * @param \ArrayObject $project
     */
    private function writePermissions(\ArrayObject $project)
    {
        $permissionsPath = $this->fileSystemProvider->getProjectConfigDirectory($project["name"]) . "/permissions.xml";
        $this->dialogProvider->logConfig("Writing the project's permissions config to " . $permissionsPath);
        $permissions = new \SimpleXMLElement('<?xml version="1.0" ?><config></config>');
        /** @var PermissionDefinition $permission */
        foreach ($project["permissions"] as $permission) {
            $var = $permissions->addChild('var');
            $var->addAttribute("name", $permission->getPath());
            foreach ($permission->getAcl() as $acl) {
                $var = $this->addItem($var, $acl);
            }
        }
        $this->writeToFile($permissions, $permissionsPath);
    }

    /**
     * @param \ArrayObject $project
     */
    private function writeBackup(\ArrayObject $project)
    {
        $backupPath = $this->fileSystemProvider->getProjectConfigDirectory($project["name"]) . "/backup.xml";
        $this->dialogProvider->logConfig("Writing the project's backup excludes config to " . $backupPath);
        $backup = new \SimpleXMLElement('<?xml version="1.0" ?><config></config>');
        $var = $backup->addChild('var');
        $var->addAttribute("name", "backup.excludes");
        foreach ($project["backupexcludes"] as $backupexclude) {
            $var = $this->addItem($var, $backupexclude);
        }
        $this->writeToFile($backup, $backupPath);
    }

}
