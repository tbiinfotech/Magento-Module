description      "Installs/Configures redis"
long_description IO.read(File.join(File.dirname(__FILE__), 'README.md'))
version          "0.0.3"
recipe            "redis", "Includes the package recipe by default."
recipe            "redis::package", "Sets up a redis server."
recipe            "redis::gem", "Installs redis gem for ruby development."
recipe            "redis::source", "Builds redis server from sources."
recipe            "redis::remove", "Removes redis server and redis gem, if installed."

depends           "apt"

%w{ ubuntu debian }.each do |os|
  supports os
end